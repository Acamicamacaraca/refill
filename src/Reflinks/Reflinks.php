<?php
/*
	Copyright (c) 2014, Zhaofeng Li
	All rights reserved.
	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:
	* Redistributions of source code must retain the above copyright notice, this
	list of conditions and the following disclaimer.
	* Redistributions in binary form must reproduce the above copyright notice,
	this list of conditions and the following disclaimer in the documentation
	and/or other materials provided with the distribution.
	THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
	AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
	IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
	DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
	FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
	DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
	SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
	CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
	OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
	OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

/*
	Main class
*/

namespace Reflinks;

use Reflinks\Exceptions\LinkHandlerException;

// Let's leave them hard-coded for now...
use Reflinks\CitationGenerators\CiteTemplateGenerator;
use Reflinks\CitationGenerators\PlainCs1Generator;
use Reflinks\LinkHandlers\StandaloneLinkHandler;

class Reflinks {
	public $optionsProvider = null;
	public $options = null;
	public $spamFilter = null;
	public $wikiProvider = null;
	
	const SKIPPED_UNKNOWN = 0;
	const SKIPPED_NOTITLE = 1;
	const SKIPPED_HANDLER = 2;
	
	const STATUS_SUCCESS = 0;
	const STATUS_FAILED = 1;
	
	const FAILURE_NOSOURCE = 1;
	const FAILURE_PAGENOTFOUND = 2;
	
	const SOURCE_TEXT = 0;
	const SOURCE_WIKI = 1;
	
	function __construct( array $objects = array() ) {
		global $config;
		if ( isset( $objects['optionsProvider'] ) ) {
			$this->optionsProvider = $objects['optionsProvider'];
		} else {
			$this->optionsProvider = new UserOptionsProvider();
		}
		
		if ( isset( $objects['options'] ) ) {
			$this->options = $objects['options'];
		} else {
			$this->options = new UserOptions( $this->optionsProvider );
			$this->options->load( $_GET );
			$this->options->load( $_POST );
		}
		
		if ( isset( $objects['spider'] ) ) {
			$this->spider = $objects['spider'];
		} else {
			$this->spider = new Spider( $config['useragent'] );
		}
		
		if ( isset( $objects['spamFilter'] ) ) {
			$this->spamFilter = $objects['spamFilter'];
		} else {
			$this->spamFilter = new SpamFilter();
		}
		
		if ( isset( $objects['wikiProvider'] ) ) {
			$this->wikiProvider = $objects['wikiProvider'];
		} else {
			$this->wikiProvider = new WikiProvider();
		}
	}
	
	public function fix( $wikitext, &$log = array() ) {
		$cm = new CitationManipulator( $wikitext );
		$log = array(
			'fixed' => array(), // ['url'] contains the original link
			'skipped' => array(), // ['ref'] contains the original ref, ['reason'] contains the reason const, ['status'] contains the status code
		);
		$dateformat = Utils::detectDateFormat( $wikitext );
		$handler = new StandaloneLinkHandler( $this->spider );
		$options = &$this->options;
		$spamFilter = &$this->spamFilter;
		$app = &$this;
		$callback = function( $citation ) use ( &$cm, &$log, &$options, &$spamFilter, $dateformat, $handler, $app ) {
			$status = 0;
			$oldref = array();
			$core = $citation['content'];
			$unchanged = false;
			// Let's check if we are supposed to mess with it first...
			if ( preg_match( "/\{\{(Dead link|404|dl|dead|Broken link)/i", $core ) ) { // dead link tag
				continue;
			}
	 
			// Let's find out what kind of reference it is...
			$tcore = trim( $core );
			if ( filter_var( $tcore, FILTER_VALIDATE_URL ) && strpos( $tcore, "http" ) === 0 ) {
				// a bare link (consists of only a URL)
				$oldref['url'] = $tcore;
			} elseif ( preg_match( "/^\[(http[^\] ]+) ([^\]]+)\]$/i", $tcore, $cmatches ) ) {
				// a captioned plain link (consists of a URL and a caption, surrounded with [], with /no/ other stuff after it)
				if ( filter_var( $cmatches[1], FILTER_VALIDATE_URL ) && !$options->get( "nofixcplain" ) ) {
					$oldref['url'] = $cmatches[1];
					$oldref['caption'] = $cmatches[2];
				} else {
					return;
				}
			} elseif ( preg_match( "/^\[(http[^ ]+)\]$/i", $tcore, $cmatches ) ) {
				// an uncaptioned plain link (consists of only a URL, surrounded with [])
				if ( filter_var( $cmatches[1], FILTER_VALIDATE_URL ) && !$options->get( "nofixuplain" ) ) {
					$oldref['url'] = $cmatches[1];
				} else {
					return;
				}
			} elseif ( preg_match( "/^\{\{cite web\s*\|\s*url=(http[^ \|]+)\s*\}\}$/i", $tcore, $cmatches ) ) {
				// an uncaptioned {{cite web}} template (Please improve the regex)
				if ( filter_var( $cmatches[1], FILTER_VALIDATE_URL ) && !$options->get( "nofixutemplate" ) ) {
					$oldref['url'] = $cmatches[1];
				} else {
					return;
				}
			} else {
				// probably already filled in, let's skip it
				return;
			}
			
			if ( $spamFilter->check( $oldref['url'] ) ) {
				$log['skipped'][] = array(
					'ref' => $core,
					'reason' => $app::SKIPPED_SPAM,
					'status' => $status,
				);
				return;
			}
		
			try {
				$metadata = $handler->getMetadata( $oldref['url'] );
			} catch ( LinkHandlerException $e ) {
				$message = $e->getMessage();
				if ( !empty( $message ) ) {
					$description = $message;
				} else {
					$description = $handler->explainErrorCode( $e->getCode() );
				}
				$log['skipped'][] = array(
					'ref' => $core,
					'reason' => $app::SKIPPED_HANDLER,
					'description' => $description,
				);
				$unchanged = true;
			}
			// finally{} is available on PHP 5.5+, but we need to maintain compatibility with 5.3... What a pity :(

			if ( !$unchanged && empty( $metadata->title ) ) {
				$log['skipped'][] = array(
					'ref' => $core,
					'reason' => $app::SKIPPED_NOTITLE,
					'status' => $response->header['http_code'],
				);
				$unchanged = true;
			}

			if ( !$unchanged ) {	
				// Generate cite template
				if ( $options->get( "plainlink" ) ) { // use plain CS1
					$generator = new PlainCs1Generator( $options );
				} else { // use {{cite web}}
					$generator = new CiteTemplateGenerator( $options );
				}
				$newcore = $generator->getCitation( $metadata, $dateformat );

				// Let's deal with the duplicated references
				if ( $cm->hasDuplicates( $core ) ) {
					$duplicates = $cm->searchByContent( $core );
					$attributes = array();
					$startAttrs = "";
					foreach ( $duplicates as $duplicate ) {
						if ( isset( $duplicate['attributes'] ) ) { // So one of the duplicates has a name
							foreach ( $duplicate['attributes'] as $name => $value ) {
								$attributes[$name] = $value;
							}
						}
					}
					if ( empty( $attributes['name'] ) ) {
						if ( !empty( $metadata->author ) ) {
							$attributes['name'] = strtolower( str_replace( " ", "", $metadata->author ) );
						} else {
							$attributes['name'] = Utils::getBaseDomain( $metadata->url );
						}
						if ( $cm->hasExactAttribute( "name", $attributes['name'] ) ) {
							$suffix = 1;
							while ( true ) {
								if ( $cm->hasExactAttribute( "name", $attributes['name'] . $suffix ) ) {
									$suffix++;
								} else {
									break;
								}
							}
							$attributes['name'] .= $suffix;
						}
					}
					foreach ( $attributes as $name => $value ) {
						$startAttrs .= $cm->generateAttribute( $name, $value ) . " ";
					}
					$replacement = $cm->generateCitation( $newcore, $startAttrs );
					$stub = $cm->generateStub( $startAttrs );
					$cm->replaceFirstOccurence( $citation['complete'], $replacement );
					$cm->replaceByContent( $core, $stub );
				} else { // Just keep the original surrounding tags
					$replacement = $citation['startTag'] . $newcore . $citation['endTag'];
					$cm->replaceFirstOccurence( $citation['complete'], $replacement );
				}
				$log['fixed'][] = array(
					'url' => $oldref['url']
				);
			}
		};
		$cm->loopCitations( $callback ); // Do it!
		return $cm->exportWikitext();
	}
	public function getResult() {
		global $config;
		$result = array();
		
		// Fetch the source wikitext
		if ( $text = $this->options->get( "text" ) ) {
			$result['old'] = $text;
			$result['source'] = self::SOURCE_TEXT;
		} elseif ( $page = $this->options->get( "page" ) ) {
			if ( !$this->options->get( "wiki" ) ) {
				$this->options->set( "wiki", "en" ); // TODO: Fix this hard-coded default
			}
			if ( !$wiki = $this->wikiProvider->getWiki( $this->options->get( "wiki" ) ) ) {
				$result['status'] = self::STATUS_FAILED;
				return $result;
			}
			$source = $wiki->fetchPage( $page, $this->spider );
			if ( !$source['successful'] ) {
				$result['status'] = self::STATUS_FAILED;
				$result['failure'] = self::FAILURE_PAGENOTFOUND;
				return $result;
			}
			$result['old'] = $source['wikitext'];
			$result['source'] = self::SOURCE_WIKI;
			$result['api'] = $wiki->api;
			$result['indexphp'] = $wiki->indexphp;
			$result['actualname'] = $source['actualname'];
			$result['edittimestamp'] = Utils::generateWikiTimestamp( $source['timestamp'] );
		} else {
			$result['status'] = self::STATUS_FAILED;
			$result['failure'] = self::FAILURE_NOSOURCE;
			return $result;
		}
		
		// Fix the wikitext
		$result['new'] = $this->fix( $result['old'], $result['log'] );
		if ( !$this->options->get( "noremovetag" ) ) {
			$result['new'] = Utils::removeBareUrlTags( $result['new'] );
		}
		
		// Generate default summary
		$counter = count( $result['log']['fixed'] );
		$counterskipped = count( $result['log']['skipped'] );
		$result['summary'] = str_replace( "%numfixed%", $counter, $config['summary'] );
		$result['summary'] = str_replace( "%numskipped%", $counterskipped, $result['summary'] );
		$result['timestamp'] = Utils::generateWikiTimestamp();
		
		$result['status'] = self::STATUS_SUCCESS;
		return $result;
	}
	public function getSkippedReason( $reason ) {
		switch ( $reason ) {
			default:
			case self::SKIPPED_UNKNOWN:
				return "Unknown error";
			case self::SKIPPED_HANDLER:
				return "Processing error";
			case self::SKIPPED_NOTITLE:
				return "No title is found";
			case self::SKIPPED_SPAM:
				return "Spam blacklist";
		}
	}
}

