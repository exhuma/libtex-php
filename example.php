<?php
/**
 * ***************************************************************************
 *
 * An example extension of the default HtmlTexer class. This one in particular
 * parses TikiWiki documents and overrides/extends the necessary methods.
 *
 * This should work if you put it in a subfolder of your tiki-installation
 *
 * ***************************************************************************
 */

#
# TIKI setup
#
require_once ('../tiki-setup.php');
require_once ('lib/wiki/wikilib.php');
require_once ('db/tiki-db.php');
require_once ('libtex.php');           // for the HtmlTexer class
$db = new TikiDB($dbTiki);

/**
 * Override some methods of the default texer to implement specific tiki markup
 */
class TikiTexer extends HtmlTexer {

   /**
    * EXAMPLE "autoscale" interpretation
    * If we find a table which has it's summary set to "autoscale", we will
    * return a scaled table. Other attributes are obviously possible.
    */
   function process_table ($node){
      if (trim(strtolower($node->getAttribute("summary"))) == "autoscale"){
         return parent::process_table( $node, true );
      } else {
         return parent::process_table( $node );
      }
   }

   /**
    * TikiWiki's WYSIWYG editor creates invalid HTML code which causes tidy to
    * create empty LI tags
    */
   function process_li ($node){
      if (trim($node->textContent) == ""){
         return "";
      } else {
         return parent::process_li( $node );
      }
   }

   /**
    * Clean output of internal wiki links
    */
   function process_a( $node ){
      $match = null;
      preg_match( "/tiki-(?:index|editpage)\.php\?page=(.+)$/U", $node->getAttribute("href"), $match );
      if ( $match ){
         return " \\underline{"
            . $this->latex_escape($node->nodeValue)
            . "}\\footnote{"
            . $this->latex_escape(urldecode($match[1]))
            . "}";
      } else {
         return parent::process_a( $node );
      }
   }

   /**
    * Ignore image indicating extarnal links
    */
   function process_img( $node ){
      if ( $node->getAttribute("src") == "img/icons/external_link.gif" ){
         return "";
      }
      return parent::process_img( $node );
   }

   /**
    * EXAMPLE to implement custom CSS classes on DIV tags
    */
   function process_div( $node ){
      if ( $node->hasAttribute("class") ){
         $css_classes = explode( " ", strtolower($node->getAttribute("class")) );
         if ( in_array( "code", $css_classes ) ){
            return $this->process_code( $node );
         } elseif ( in_array( "warning", $css_classes ) ){
            return '\begin{center}\fcolorbox{wborder}{wback}{\parbox[t]{90mm}{ \textbf{Warning:}\\\\\\\\' . parent::process_container( $node ) . ' }}\end{center}';
         }elseif ( in_array( "note", $css_classes ) ){
            return '\begin{center}\fcolorbox{nborder}{nback}{\parbox[t]{90mm}{ \textbf{Note:}\\\\\\\\' . parent::process_container( $node ) . ' }}\end{center}';
         }
      }
      return $output . parent::process_div( $node );
   }

}

$tmpfname = tempnam("/tmp", "htmltexer_");
$tmpimgfolder = $tmpfname."_imgs";

if ( !is_dir( $tmpimgfolder ) ){
   mkdir( $tmpimgfolder );
}

// file gallery setting (for embedded images)
$query = "select `value` from `tiki_preferences` where `name` = 'fgal_use_dir';";
$result = $db->query($query);
if ( !$result ) {
   die( "Unable to specify image path" );
}
$tmp = $result->fetchRow();
$tiki_image_path = $tmp['value'];

/**
 * Remove a tree and all it's containing files from disk
 */
function del_tree($dir) {
   $files = glob( $dir . '*', GLOB_MARK );
   foreach( $files as $file ){
      if( substr( $file, -1 ) == '/' )
         del_tree( $file );
      else
         unlink( $file );
   }
   if (is_dir($dir)) rmdir( $dir );
}

/**
 * Fetch a Tiki image and put it into a folder readable for the latex processor
 */
function fetch_image( $url, $target_folder ){
   global $db, $tiki_image_path;
   if ( $_REQUEST['output'] == "debug" ){
      printf("Fetching image %s\n", $url );
   }
   if ( !is_dir( $target_folder ) ){
      print "Folder '$target_folder' does not exist";
      return;
   }

   $file_info = false;

   // TIKI Images
   preg_match( "/^\/tiki-download_file\.php\?fileId=(\d+)&.*$/U", $url, $file_info );
   if ( $file_info ){
      if ( $_REQUEST['output']=="debug" ) printf("Matched TIKI image #%s\n", $file_info[1] );
      $query = "select `path`, `filetype` from `tiki_files` where `fileId` = ".$file_info[1].";";
      $result = $db->query($query);
      if ( !$result ) {
         die( "Unable to specify image path" );
      }
      $row = $result->fetchRow();
      $file_hash = $row['path'];

      switch( strtolower($row['filetype']) ){
      case "image/jpeg":
      case "image/jpg":
         $ext = "jpg";
         break;
      case "image/png":
         $ext = "png";
         break;
      default:
         $ext = "unknown";
         break;
      }
      $target_file = "$target_folder/" . md5($url). ".$ext";
      copy( "$tiki_image_path/$file_hash", $target_file );
   } else {
      // GRAPHVIZ images
      preg_match( "/^\/lib\/graphviz\/graph\.php\?src=.*$/U", $url, $file_info );
      if ( !$file_info ){
         return;
      }
      if ( $_REQUEST['output']=='debug' ) printf("Matched GRAPHVIZ image\n" );
      // graphviz on CentOS can only create GIF, but we need PNG. Let's convert
      $im = @imagecreatefromgif( "http://" . $_SERVER['SERVER_NAME'] . "/$url" );
      $target_file = "$target_folder/" . md5($url). ".png";
      imagepng( $im, $target_file );
   }
}

#
# Retrieve the page and backlinks, parse wiki syntax into HTML and cleanup
#
$page_data = $tikilib->get_page_info($_REQUEST['page']);
$backlinks = $wikilib->get_backlinks($_REQUEST['page']);
$html = $tikilib->parse_data( $page_data['data'] );
$html = str_replace( "&#160;", "", $html );
HtmlTexer::fix_headings($html);

#
# append backlinks
#
if ( count( $backlinks ) > 0 ){
   $html .= "<h1>This document is referenced by the following documents</h1>";
   $html .= "<ul>";
   foreach( $backlinks as $backlink ){
      $html .= "<li>" . $backlink['fromPage'] . "</li>";
   }
   $html .= "</ul>";
}

#
# The LaTeX Preamble
#
$preamble = sprintf ("
\\documentclass[a4paper]{article}
\\usepackage[utf8]{inputenc}
\\usepackage[pdftex]{graphicx}
\\usepackage[usenames]{color}
\\usepackage{fancyhdr}
\\definecolor{wborder}{rgb}{1,0,0}
\\definecolor{wback}{rgb}{0.9,0.9,0.7}
\\definecolor{nborder}{rgb}{1,0,0}
\\definecolor{nback}{rgb}{0.7,0.7,0.9}
\\title{%s -- rev. %d}
\\date{%s}
\\author{%s}
\\pagestyle{fancy}
\\fancyhead[LO]{\\bfseries %s -- rev. %d}
\\fancyhead[RO]{%s}
",
$page_data['pageName'],
$page_data['version'],
date("d. M Y @ H:i:s", $page_data['lastModif']),
$page_data['user'],
$page_data['pageName'],
$page_data['version'],
date("d. M Y", $page_data['lastModif'])
);


// Pear's most verbose log level is PEAR_LOG_DEBUG (=7) so "20" gives us some
// breathing room if other levels will be added in the future.
$texer = new TikiTexer( $preamble, $tmpfname."_imgs/", ($_REQUEST['output']=='debug' ? 20 : 0));
$texer->parse_html( $html );

#
# Fetch images
#
foreach( $texer->images() as $url ){
   fetch_image( $url, $tmpimgfolder );
}

#
# Store the Tex document
#
$handle = fopen($tmpfname, "w");
fwrite( $handle, $texer->tex() );
fclose($handle);

$current_dir = getcwd();
chdir(dirname($tmpfname));

#
# Convert to pdf and respond. (Or output debug info)
#
switch( $_REQUEST['output'] ){
case "debug":
   header( "Content-Type: text/plain" );
   print "--- HTMLified Doc ------------------------------------------\n";
   var_dump( $html );
   print "--- DOM tree -----------------------------------------------\n";
   var_dump( $texer->dom->saveXML() );
case "src":
case "raw":
case "latex":
case "tex":
   header( "Content-Type: text/plain" );
   print $texer->tex();
   break;

case "pdf":
default:
   $command = "/usr/bin/pdflatex --interaction=nonstopmode $tmpfname &>/dev/null ";
   $sysout_1 = system($command, $exec_status_1 );
   # run again (required for table of contents, bibliography, cross-references e.t.c.)
   $sysout_2 = system($command, $exec_status_2 );

   header( "Content-Type: application/pdf" );
   header('Content-Disposition: attachment; filename=test.pdf');
   readfile( "$tmpfname.pdf" );
   unlink( $tmpfname . ".pdf" );
   unlink( $tmpfname . ".log" );
   unlink( $tmpfname . ".aux" );
   break;
}

#
# Cleanup
#
unlink( $tmpfname );
del_tree( $tmpimgfolder );
chdir($current_dir);

?>
