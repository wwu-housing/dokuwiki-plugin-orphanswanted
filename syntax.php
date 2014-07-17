<?php
/**
 * OrphansWanted Plugin: Display Orphans, Wanteds and Valid link information
 *
 * syntax ~~ORPHANSWANTED:<choice>[!<exclude list>]~~  <choice> :: orphans | wanted | valid | all
 * [!<exclude list>] :: optional.  prefix each with ! e.g., !wiki!comments:currentyear
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     <dae@douglasedmunds.com>
 * @author     Andy Webber <dokuwiki at andywebber dot com>
 * @author     Federico Ariel Castagnini
 * @author     Cyrille37 <cyrille37@gmail.com>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

require_once(DOKU_INC.'inc/search.php');

define('DEBUG', 0);

//-------------------------------------

function orph_callback_search_wanted(&$data,$base,$file,$type,$lvl,$opts) {

	if($type == 'd'){
		return true; // recurse all directories, but we don't store namespaces
	}

    if(!preg_match("/.*\.txt$/", $file)) {  // Ignore everything but TXT
		return true;
	}

	// search the body of the file for links
	// dae mod
  //	orph_Check_InternalLinks(&$data,$base,$file,$type,$lvl,$opts);
	orph_Check_InternalLinks($data,$base,$file,$type,$lvl,$opts);

	// get id of this file
	$id = pathID($file);

	//check ACL
	if(auth_quickaclcheck($id) < AUTH_READ) {
		return false;
	}

	// try to avoid making duplicate entries for forms and pages
	$item = &$data["$id"];
	if(isset($item)) {
		// This item already has a member in the array
		// Note that the file search found it
		$item['exists'] = true;
    } else {
		// Create a new entry
		$data["$id"]=array('exists' => true,
				 'links' => 0);
	}
	return true;
}

function orph_handle_link( &$data, $link )
{
	if( isset($data[$link]) )
  {
		// This item already has a member in the array
		// Note that the file search found it
		$data[$link]['links'] ++ ;   // count the link
  } else {
		// Create a new entry
		$data[$link] = array(
      'exists' => false,  // Only found a link, not the file
      'links' => 1
    );
		// echo "      <!-- added link to list --> \n";
  }
	if (DEBUG) echo "<p>-- New count for link <b>" . $link . "</b>: " . $data[$link]['links'] . "</p>\n";
}


/**
 * Search for internal wiki links in page $file
 */
function orph_Check_InternalLinks( &$data, $base, $file, $type, $lvl, $opts )
{
  global $conf;
  define('LINK_PATTERN', '%\[\[([^\]|#]*)(#[^\]|]*)?\|?([^\]]*)]]%');

  if( ! preg_match("/.*\.txt$/", $file) )
  {
    return ;
  }

  $currentID = pathID($file);
  $currentNS = getNS($currentID);

  if(DEBUG) echo sprintf("<p><b>%s</b>: %s</p>\n", $file, $currentID);

  // echo "  <!-- checking file: $file -->\n";
  $body = @file_get_contents($conf['datadir'] . $file);

  // ignores entries in <nowiki>, %%, <code> and emails with @
  foreach( array(
              '/<nowiki>.*?<\/nowiki>/',
              '/%%.*?%%/',
              '/<code .*?>.*?<\/code>/'
            )
            as $ignored )
  {
    $body = preg_replace($ignored, '',  $body);
  }

  $links = array();
  preg_match_all( LINK_PATTERN, $body, $links );

  foreach( $links[1] as $link )
  {
        if(DEBUG) echo sprintf("--- Checking %s<br />\n", $link);

		if( (0 < strlen(ltrim($link)))
			   and ! preg_match('/^[a-zA-Z0-9\.]+>{1}.*$/u',$link) // Interwiki
			   and ! preg_match('/^\\\\\\\\[\w.:?\-;,]+?\\\\/u',$link) // Windows Share
			   and ! preg_match('#^([a-z0-9\-\.+]+?)://#i',$link) // external link (accepts all protocols)
			   and ! preg_match('<'.PREG_PATTERN_VALID_EMAIL.'>',$link) // E-Mail (pattern above is defined in inc/mail.php)
			   and ! preg_match('!^#.+!',$link) // inside page link (html anchor)
         ) {
                $pageExists=false;
                resolve_pageid($currentNS,$link,$pageExists );
                if (DEBUG) echo sprintf("---- link='%s' %s ", $link, $pageExists?'EXISTS':'MISS');

                if(((strlen(ltrim($link)) > 0)           // there IS an id?
                         and !auth_quickaclcheck($link) < AUTH_READ)) {    // should be visible to user
                            //echo "      <!-- adding $link -->\n";

                          if(DEBUG) echo ' A_LINK' ;

                            $link= strtolower( $link );
                            orph_handle_link($data, $link);
                }
                else
                {
                  if(DEBUG) echo ' EMPTY_OR_FORBIDDEN' ;
                }
		} // link is not empty and is a local link?
		else
		{
			 if(DEBUG) echo ' NOT_INTERNAL';
        }

		if(DEBUG) echo "<br />\n";
	} // end of foreach link
}



// --------------------

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_orphanswanted extends DokuWiki_Syntax_Plugin {
    /**
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Doug Edmunds',
            'email'  => 'dae@douglasedmunds.com',
            'date'   => @file_get_contents(dirname(__FILE__) . '/VERSION'),
            'name'   => 'OrphansWanted Plugin',
            'desc'   => 'Find orphan pages and wanted pages .
            syntax ~~ORPHANSWANTED:<choice>[!<excluded namespaces>]~~ .
            <choice> :: orphans|wanted|valid|all .
            <excluded namespaces> are optional, start each namespace with !' ,
            'url'    => 'http://dokuwiki.org/plugin:orphanswanted',
        );
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'normal';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 990;     // was 990
    }


    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~ORPHANSWANTED:[\w:!]+~~', $mode, 'plugin_orphanswanted');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        $match_array = array();
        $match = substr($match,16,-2); //strip ~~ORPHANSWANTED: from start and ~~ from end

        // Wolfgang 2007-08-29 suggests commenting out the next line
        // $match = strtolower($match);
        //create array, using ! as separator
        $match_array = explode("!", $match);
        // $match_array[0] will be orphan, wanted, valid, all, or syntax error
        // if there are excluded namespaces, they will be in $match_array[1] .. [x]
        // this return value appears in render() as the $data param there

        return $match_array;
    }

    /**
     * Create output
     */
    function render($format, &$renderer, $data) {
        global $INFO, $conf;
        $helper = plugin_load('helper','orphanswanted');

        if($format == 'xhtml') {
            // prevent caching to ensure content is always fresh
            $renderer->info['cache'] = false;

            // $data is an array
            // $data[1]..[x] are excluded namespaces, $data[0] is the report type
            //handle choices

            switch ($data[0]) {
                case 'orphans':
                    $renderer->doc .= $helper->orphan_pages($data);
                    break;
                case 'wanted':
                    $renderer->doc .= $helper->wanted_pages($data);
                    break;
                case 'valid':
                    $renderer->doc .= $helper->valid_pages($data);
                    break;
                case 'all':
                    $renderer->doc .= $helper->all_pages($data);
                    break;
                default:
                    $renderer->doc .= "ORPHANSWANTED syntax error";
                   // $renderer->doc .= "syntax ~~ORPHANSWANTED:<choice>~~<optional_excluded>  <choice> :: orphans|wanted|valid|all  Ex: ~~ORPHANSWANTED:valid~~";
            }
            return true;
        }

        return false;
    }
}

?>
