<?php

/**
 * ***************************************************************************
 *
 * Convert a HTML document to LaTeX (and PDF)
 *
 * DEPENDENCIES
 *
 *    - PHP >= 5.0 (XML DOM API)
 *    - PEAR::LOG
 *    - Tidy
 *
 * USAGE
 *
 *    The class will loop through the DOM elements. For each element it will
 *    call a method called "process_<tagname>". If the method does not exist,
 *    it will create an error block in the resulting TEX document.
 *
 *    For a practical usage example see "example.php"
 *
 * ***************************************************************************
 *
 * libtex.php - Convert HTML to TEX - Copyright (C) 2008 Michel Albert
 *
 * This library is free software; you can redistribute it and/or modify it
 * under the terms of the GNU Lesser General Public License as published by the
 * Free Software Foundation; either version 2.1 of the License, or (at your
 * option) any later version.
 *
 * This library is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public License
 * for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this library; if not, write to the Free Software Foundation,
 * Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 *
 * ***************************************************************************
 *
 */

require_once 'pear/Log.php';

class HtmlTexer {

   /** The version string for display uses*/
   const __VERSION__ = '1.0b0';

   /** Unique (ever increasing) version code (for programmatical use). */
   const __VERSION_CODE__ = 1;

   /**
    * Constructor
    *
    * @param $preamle: Custom LaTeX preamble (default = "")
    * @param $image_folder: The folder which contains the image files
    *                       (default = "")
    * @param $log_level: The PEAR::LOG level (defaul = PEAR_LOG_EMERG)
    */
   function __construct( $preamble="", $image_folder="", $log_level=PEAR_LOG_EMERG ){

      $this->log = &Log::singleton('display', '', 'HtmlTexer',
         array('linebreak'=>"\n", 'lineFormat'=>'%2$s [%3$s] %4$s'),
         $log_level);
      $this->_preamble = $preamble;

      // Tidy configuration
      $this->tidy_config = array(
                 'add-xml-decl'        => true,
                 'add-xml-space'       => true,
                 'indent'              => false,
                 'clean'               => true,
                 'drop-empty-paras'    => true,
                 'drop-font-tags'      => true,
                 'drop-proprietary-attributes' => true,
                 'enclose-block-text'  => true,
                 'output-xml'          => true,
                 'numeric-entities'    => true,
                 'break-before-br'     => true,
                 'lower-literals'      => true,
                 'wrap'                => 0);

      $this->image_folder = $image_folder;
      $this->supported_images = array( ".jpg", ".png", ".pdf", ".gif" );

      #todo# maketitle should be optional
      $this->header = "\\begin{document}\n\\maketitle\n";
      $this->footer = "\n\\end{document}";
      $this->texHeader = "";

      $this->_preamble .= "
\\graphicspath{{{$image_folder}}}
\\DeclareGraphicsExtensions{" . implode(",", $this->supported_images) . "}

%
% Package used for source-code listings
%
\\usepackage{listings}

%
% Package used for strike-through text
%
\\usepackage{ulem}

%
% Macro to define maximum width of images
%
\\makeatletter
\\def\\maxwidth{\\ifdim\\Gin@nat@width>\\linewidth\\linewidth
\\else\\Gin@nat@width\\fi}
\\makeatother

\\definecolor{listing}{rgb}{0.941, 0.941, 0.871}
\\definecolor{core_error_fg}{rgb}{0, 0, 0}
\\definecolor{core_error_bg}{rgb}{1, 0, 0}
\\lstset{ numbers=left, basicstyle=\\footnotesize, backgroundcolor=\\color{listing}, language=bash, caption=Code Listing, frame=single, breaklines=true }
         ";
      //unused//$this->container_elements = array(
      //unused//   "body",
      //unused//   "div",
      //unused//   "p"
      //unused//   );

   }

   function parse_html( $html_code ){
      $this->html_code = $html_code;

      // Tidy HTML code
      $tidy = new Tidy();
      $tidy->parseString($html_code, $this->tidy_config, 'utf8');
      $tidy->cleanRepair();
      $this->tidy_code = $tidy->value;
      $this->dom = DOMDocument::loadXML($tidy->value);
      $this->dom->normalizeDocument();
      if ( $this->dom == null ){
         trigger_error( "Unable to parse XML Document!", E_USER_ERROR );
      }
   }

   /**
    * Give helpful hints for non-implemented tags
    */
   function __call( $method, $args ){
      $output = '\begin{center}';
      $output .= "\\fcolorbox{core_error_fg}{core_error_bg}{";
      $output .= "\\parbox[t]{90mm}{";
      $output .= '\textbf{LibTex Error}\\\\ ``' . $this->latex_escape($method)."'' not yet implemented";
      $output .= '\\\\\textbf{Content:}\\\\';
      $output .= $this->process_container( $node );
      $output .= "}";
      $output .= "}";
      $output .= '\end{center}';
      return $output;
   }

   /**
    * Returns the image urls encountered in this document. They need to be
    * downloaded before they can be used in the document
    */
   function images(){
      $imgs = $this->dom->getElementsByTagName("img");
      $urls = array();
      for ($i=0; $i<$imgs->length; $i++ ){
         $urls[] = $imgs->item($i)->getAttribute("src");
      }
      return $urls;
   }

   /**
    * IMG tags
    * For compatibility with "download.php"-like src urls, we will use a
    * md5-hash of the "src" attribute as filename. Note that the extension (if
    * available) is included in the hash.
    *
    * Images need to be prepared and put into the image folder specified upon
    * the Texer's creation. It is up to the user of this library to clean up
    * that folder after the document has been created.
    *
    * It is up to the user of this library to ensure that the correct extension
    * is appended (see example 2). The file-extenstion is required by LaTeX!
    *
    * Examples: src="img/foo.jpg" --> ab502eb247b65f597780a4ee53c2fabd.jpg
    *           src="/dwld.php?file_id=2" --> 66d29550c3f52affa0ceca2365ad1b50.?
    */
   function process_img( $node ){
      $filename = md5($node->getAttribute("src"));

      $found = false;
      foreach( $this->supported_images as $extension ){
         $found |= file_exists( $this->image_folder . "/" . $filename . $extension );
      }

      if ( $found ) {
         return "\\includegraphics[width=\\maxwidth]{" . $filename . "}";
      } else {
         return "[IMAGE $filename. File not found]";
      }
   }

   /**
    * U Tags
    */
   function process_u( $node ){
      return " \\underline{" . $this->latex_escape( trim($node->nodeValue) ) . "} ";
   }

   /**
    * STRIKE Tags
    */
   function process_strike( $node ){
      return " \\sout{" . $this->latex_escape( trim($node->nodeValue) ) . "} ";
   }

   /**
    * SPAN tags
    */
   function process_span( $node ){
      return $this->process_container( $node );
   }

   /**
    * Process the node as a container-node (i.e. loop through all childs
    * recursively if necessary )
    *
    * @todo: the function name is not well chosen! If we convert an XML document with nodes that carry the name "container", then this may result in unpredictable behaviour.
    */
   function process_container( $node ){
      $output = "";
      for( $i = 0; $i < $node->childNodes->length; $i++ ){
         $output .= $this->process_element( $node->childNodes->item($i) );
      }
      return $output;
   }

   /**
    * STRONG tags
    */
   function process_strong( $node ){
      return " \\textbf{" . $this->latex_escape( trim($node->nodeValue) ) . "} ";
   }

   /**
    * SUP tags
    */
   function process_sup( $node ){
      return "$^{" . $this->latex_escape( trim($node->nodeValue) ) . "}$";
   }


   /**
    * PRE tags
    */
   function process_pre( $node ){
      return $this->process_code( $node );
   }

   /**
    * CODE tags
    */
   function process_code( $node ){
      $boxname = md5($node->nodeValue);
      $output .= '\begin{lstlisting}';
      $output .= $node->nodeValue;
      $output .= '\end{lstlisting}';
      return $output;
   }

   /**
    * LI tags
    */
   function process_li( $node ){
      $output = $this->process_container( $node );
      if ( trim($output) == ""){
         return "";
      } else {
         return "\\item{ " . $output . " }";
      }
   }

   /**
    * EM Tags
    */
   function process_em( $node ){
      return " \\textit{" . $this->latex_escape( trim($node->nodeValue) ) . "} ";
   }

   /**
    * UL Tags
    */
   function process_ul( $node ){
      $output = "\n\\begin{itemize}\n";
      $output .= $this->process_container( $node );
      $output .= "\\end{itemize}\n\n";
      return $output;
   }

   /**
    * OL Tags
    */
   function process_ol( $node ){
      $output = "\n\\begin{enumerate}\n";
      $output .= $this->process_container( $node );
      $output .= "\\end{enumerate}\n\n";
      return $output;
   }

   /**
    * DIVs
    */
   function process_div( $node ){
      return $this->process_p( $node );
   }

   /**
    * Hyperlinks
    */
   function process_a( $node ){
      $href = $node->getAttribute( "href" );
      $text = $node->nodeValue;

      $output = " \\underline{" . $this->latex_escape($node->nodeValue) . '}';
      if ( $text != $href ){
         $output .= '\footnote{'. $this->latex_escape($href) ."}";
      }
      return $output;
   }

   /**
    * Delegate "B" tags to the "process_strong" methods. Not semantically
    * correct, but for now it's good enough
    *
    * @todo: Separate this from the "strong" method as they are both semantically different
    */
   function process_b( $node ){
      return $this->process_strong( $node );
   }

   /**
    * DEL Tags
    */
   function process_del( $node ){
      $output = "\\sout{";
      $output .= $this->process_container($node);
      $output .= "}";
      return $output;
   }

   /**
    * Delegate "I" tags to the "process_em" methods. Not semantically correct,
    * but for now it's good enough
    *
    * @todo: Separate this from the "em" method as they are both semantically different
    */
   function process_i( $node ){
      return $this->process_em( $node );
   }

   /**
    * TABLE tags
    *
    * HTML tables are non-trivial to implement because there is a funcamental
    * difference to LaTeX tables: In LaTeX the number of columns must be known
    * ahead of time! Additionally, LaTeX will scale the table to the content.
    * If the cells contents do not wrap the text, this will result in a table
    * that extends over the edge of the page. Use the "autoscale" parameter to
    * tell this method to try and scale the table automatically.
    *
    * @param autoscale: Try to scale the table so it fits the page. May resolve
    *                   some cropping issues, but may trigger others.
    * @todo: This method contains some hardcoded values (table borders, table width). They should be changeable
    */
   function process_table( $node, $autoscale = false ){

      // If it's valid XHTML, the table should containe a "TBODY" tag to
      // contain the data rows
      $row_container = $node->getElementsByTagName( "tbody" )->item(0);
      if (!$row_container){
         // otherwise, take the whole table as row_container
         $row_container = $node;
      }

      $rows = $row_container->getElementsByTagName("tr");

      // for auto-wrapping text we store the max number of characters per
      // column.
      $colchars = array();

      // count the number of columns (keep largest count)
      $num_columns = 0;
      for ( $i = 0; $i < $rows->length; $i++ ){
         $row = $rows->item($i);
         // column count = sum of "TH" and "TD" tags.
         $dcells = $row->getElementsByTagName("td");
         $hcells = $row->getElementsByTagName("th");
         $tmp = $dcells->length + $hcells->length;
         if ( $tmp > $num_columns ){
            $num_columns = $tmp;
         }

         if ( $autoscale ){
            // determine maximum number of characters needed per column
            for ( $j = 0; $j < $dcells->length; $j++ ){
               if ( count( $colchars ) < $j+1 ) { $colchars[] = 0; }
               $colchars[$j] = max( $colchars[$j], mb_strlen($dcells->item($j)->textContent) );
            }

            for ( $j = 0; $j < $hcells->length; $j++ ){
               if ( count( $colchars ) < $j+1 ) { $colchars[] = 0; }
               $colchars[$j] = max( $colchars[$j], mb_strlen($hcells->item($j)->textContent) );
            }
         }
      }

      // distrubute column widths to content
      if ( $autoscale ) {
         $col_distrib = array();
         $this->log->debug( "Colchars: " . implode( ",", $colchars ) );
         $total_chars = array_sum( $colchars );
         // @todo: Make this hardcoded width changeable. Preferably scale it to the page width
         $table_width_em = 80;
         foreach( $colchars as $char_count ){
            $col_distrib[] = round($table_width_em * ($char_count / $total_chars));
         }
      }

      // prepare the column definitions
      $coldef = array();
      if ( $autoscale ){
         for ( $i = 0; $i<$num_columns; $i++ ){ $coldef[] = "p{{$col_distrib[$i]}ex}"; }
      } else {
         for ( $i = 0; $i<$num_columns; $i++ ){ $coldef[] = "l"; }
      }
      $coldef = "| " . implode( " | ", $coldef ) . " |";

      $output = "\\begin{center}\\begin{tabular}{{$coldef}}\n";
      $output .= "\\hline\n";
      for ( $i = 0; $i < $rows->length; $i++ ){
         $row = $rows->item($i);
         $cells = array();
         for ( $j = 0; $j < $row->childNodes->length; $j++ ){
            $current_cell = $row->childNodes->item($j);
            if ( $current_cell instanceof DOMElement ) {

               $prefix = "";
               $suffix = "";

               $colspan = $current_cell->getAttribute("colspan");
               if ( $colspan !== "" ){
                  $prefix .= "\\multicolumn{{$colspan}}{|c|}{";
                  $suffix .= "}";
               }

               if ( strtoupper($current_cell->tagName) == "TH" ) {
                  $prefix .= "\\textbf{";
                  $suffix .= "}";
               }

               $cells[] = $prefix . $this->process_container( $current_cell ) . $suffix;
            }
         }
         if ( $i > 0 ){
            $output .= "\\hline\n";
         }
         $output .= implode( " & ", $cells ) . "\\\\\n";
      }
      $output .= "\\hline\n";
      $output .= "\\end{tabular}\\end{center}";
      return $output;
   }

   /**
    * Paragraphs
    */
   function process_p( $node ){
      $output = $this->process_container( $node );
      if ( $output == "" ){
         return "";
      } else {
         return "$output\n\n";
      }
   }

   /**
    * Forced line Breaks
    */
   function process_br( $node ){
      if ( trim($node->previousSibling->textContent) !== "") {
         return "\\\\";
      }
   }

   /**
    * H1 Tags
    */
   function process_h1( $node ){
      return "\section{ " . trim($node->textContent) . " }\n";
   }

   /**
    * H2 tags
    */
   function process_h2( $node ){
      return "\subsection{ " . trim($node->textContent) . " }\n";
   }

   /**
    * H3 tags
    */
   function process_h3( $node ){
      return "\subsubsection{ " . trim($node->textContent) . " }\n";
   }

   /**
    * H4 tags
    * NOTE: LaTeX only supports 3 section levels!
    */
   function process_h4( $node ){
      return "\\textbf{ " . trim($node->textContent) . " }\n";
   }

   /**
    * H5 tags
    * NOTE: LaTeX only supports 3 section levels!
    */
   function process_h5( $node ){
      return "\\textbf{ " . trim($node->textContent) . " }\n";
   }

   /**
    * H6 tags
    * NOTE: LaTeX only supports 3 section levels!
    */
   function process_h6( $node ){
      return "\\textbf{ " . trim($node->textContent) . " }\n";
   }

   /**
    * Returns a text-node as TEX format
    */
   function process_text( $el ){
      if ( trim( $el->nodeValue ) != "" ){
         $output = trim( $el->nodeValue );
         return $this->latex_escape($output);
      }else{
         return "";
      }
   }

   /**
    * Replace special characters by their proper LaTeX escapes
    *
    * This method makes simple regex replacements. They depend strongly on the 
    * order in which they are applied.
    *
    * @todo: Remove hard-coded user-replacements (arrows) and give the library user to add custom replacements.
    */
   function latex_escape( $text ){
      if ( trim( $text ) == "" ){
         return "";
      }
      $original = $text;

      $replacements = array(
         "punctuation" => array( '/([&$%#_{}])/', '\\\\$1' ),
         "backslash" => array( '/\\\\([^_&%#${}])/', '$\\backslash$$1'),
         "circumflex" => array( '/([\^])/', '\^{}$1' ),
         "quotes" => array( '/"(.*)"/U', "``$1''"),
         "rarrow" => array( '/-+>/U', "$\\rightarrow$" ),
         "larrow" => array( '/-+>/U', "$\\leftarrow$" ),
         "Rarrow" => array( '/=+>/U', "$\\Rightarrow$" ),
         "Larrow" => array( '/=+>/U', "$\\Leftarrow$" ),
         "math_in_text" => array( '/([<>])/', '\\$$1\\$' ), // should come after the arrows
      );

      $this->log->debug( "Escaping '". $original ."'" );
      $tcount = 0;
      foreach( $replacements as $name => $p ){
         $pcount = 0;
         $text = preg_replace( $p[0], $p[1], $text, -1, $pcount );
         $tcount += $pcount;
      }
      if ( $tcount > 0 ){
         $this->log->debug( " ------: '". $text ."'" );
      }

      return $text;
   }

   /**
    * Processes a DOM element and returns a TEX string
    */
   function process_element( $el ){
      if ( $el instanceof DOMText ){
         return $this->process_text( $el );
      }
      return call_user_func( array( $this, "process_" . $el->nodeName ), $el );
      //test//if ( in_array( $el->nodeName, $this->container_elements ) ){
      //test//   $output = "";
      //test//   for ( $i=0; $i < $el->childNodes->length; $i++){
      //test//      $output .= $this->process_element( $el->childNodes->item($i) );
      //test//   }
      //test//   return $output;
      //test//} else {
      //test//}
   }

   /**
    * Return the LaTeX document
    */
   function tex(){
      return $this->_preamble
         . $this->header
         . $this->body()
         . $this->footer;
   }

   /**
    * Convert and return the HTML body as LaTeX markup.
    */
   function body(){

      // we only need the body
      $body = $this->dom->getElementsByTagName( "body" );

      if ( $body->length == 0) {
         trigger_error( "No BODY tag found in the document!", E_USER_ERROR );
         return "No BODY tag found in the document!";
      }

      if ( $body->length > 1) {
         trigger_error( "More than one BODY tag found in the document!", E_USER_ERROR );
         return "More than one BODY tag found in the document!";
      }

      $body = $body->item(0);

      /* walk through the elements */
      $output = $this->texHeader;
      for( $i=0; $i < $body->childNodes->length; $i++ ){
         $node = $body->childNodes->item($i);
         if ( $node instanceof DOMText ){
            $output .= $this->process_text( $node );
         } elseif ( $node instanceof DOMElement ){
            $output .= $this->process_element( $node );
         } else {
            $output .= "[UNKNOWN ELEMENT]";
         }
      }
      return $output;

   }

   /**
    * Finds highest level heading and replace headings, starting with H1
    * Possible HTML levels: H1-H6
    *
    * This library expects H1-H3 to exist. These three tags will be used to
    * determine document structure (sections). Sometimes HTML authors decide to
    * use "H2" to make smaller headings instead of doing this with CSS. This
    * method will "shift" the headings in such documents. After this has run,
    * the document will contain headings starting at H1.
    *
    * @param $shift: Add this value (int) to the shift operation. Normally this
    *                is not needed.
    */
   function fix_headings( &$input, $shift=0 ){
      // figure out the highest-level heading existing in the document
      $top_level = 1;
      for( $i=1; $i<=6; $i++ ){
         if ( stristr($input, "<h$i" ) ){
            $top_level = $i;
            break;
         }
      }

      // replace all levels capping at H6 (HTML does not support more)
      for ( $i=$top_level; $i<=6-$shift; $i++ ){
         $new_level = $i-$top_level+1+$shift;
         if ($debug) $this->log->debug( "replacing h$i with h$new_level\n" );
         $input = str_ireplace( "<h$i", "<h$new_level", $input );
         $input = str_ireplace( "</h$i>", "</h$new_level>", $input );
      }

      // due to shifting, it may be possible that there are headings > H6. We 
      // replace them with "<strong>" tags
      for ( $i=7; $i<=6+$shift; $i++ ){
         $input = preg_replace( "/<h$i>(.*)<\/h$i>/Ui", "<strong>$1</strong>", $input );
      }
   }

}
