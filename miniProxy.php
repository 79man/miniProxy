<?php
	/*
	miniProxy - A simple PHP web proxy. <https://github.com/joshdick/miniProxy>
	Written and maintained by Joshua Dick <http://joshdick.net>.
	miniProxy is licensed under the GNU GPL v3 <http://www.gnu.org/licenses/gpl.html>.
	*/
	
	/****************************** START CONFIGURATION ******************************/
	
	//To allow proxying any URL, set $whitelistPatterns to an empty array (the default).
	//To only allow proxying of specific URLs (whitelist), add corresponding regular expressions
	//to the $whitelistPatterns array. Enter the most specific patterns possible, to prevent possible abuse.
	//You can optionally use the "getHostnamePattern()" helper function to build a regular expression that
	//matches all URLs for a given hostname.
	$whitelistPatterns = array(
	  //Usage example: To support any URL at example.net, including sub-domains, uncomment the
	  //line below (which is equivalent to [ @^https?://([a-z0-9-]+\.)*example\.net@i ]):
	  //getHostnamePattern("example.net")
	);
	
	//To enable CORS (cross-origin resource sharing) for proxied sites, set $forceCORS to true.
	$forceCORS = false;

	/****************************** END CONFIGURATION ******************************/

	/*
	ob_start("ob_gzhandler"); // Not needed as zLib is on
	*/
	define('LOG_FILE', 'logs.txt');
	function log_msg($txt) {
		 $myfile = file_put_contents(LOG_FILE, $txt.PHP_EOL , FILE_APPEND | LOCK_EX);
	}
	function clear_log_file() {
		file_put_contents(LOG_FILE, "");
	}
	function startsWith($haystack,$needle) {
		return (substr($haystack,0,strlen($needle)) == $needle);
	}
	function endsWith($haystack,$needle) {
		return (substr($haystack,-strlen($needle)) == $needle);
	}

	if (version_compare(PHP_VERSION, '5.4.7', '<')) {
		die ("miniProxy requires PHP version 5.4.7 or later.");
	}

	if (!function_exists("curl_init")) die ("miniProxy requires PHP's cURL extension. Please install/enable it on your server and try again.");

	//Helper function for use inside $whitelistPatterns.
	//Returns a regex that matches all HTTP[S] URLs for a given hostname.
	function getHostnamePattern($hostname) {
		$escapedHostname = str_replace(".", "\.", $hostname);
		return "@^https?://([a-z0-9-]+\.)*" . $escapedHostname . "@i";
	} // function getHostnamePattern
	
	//Helper function used to removes/unset keys from an associative array using case insensitive matching
	function removeKeys(&$assoc, $keys2remove) {
		$keys = array_keys($assoc);
		$map = array();
		foreach ($keys as $key) {
			$map[strtolower($key)] = $key;
		}
		
		foreach ($keys2remove as $key) {
			$key = strtolower($key);
			if (isset($map[$key])) {
				unset($assoc[$map[$key]]);
			}
		}
	} // function removeKeys
	
	if (!function_exists("getallheaders")) {
		//Adapted from http://www.php.net/manual/en/function.getallheaders.php#99814
		function getallheaders() {
			$result = array();
			foreach($_SERVER as $key => $value) {
				if (substr($key, 0, 5) == "HTTP_") {
					$key = str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
					$result[$key] = $value;
				}
			}
			return $result;
		}
	}
	
	$prefixPort = $_SERVER["SERVER_PORT"] != 80 ? ":" . $_SERVER["SERVER_PORT"] : "";
	
	//Use HTTP_HOST to support client-configured DNS (instead of SERVER_NAME), but remove the port if one is present
	$prefixHost = $_SERVER["HTTP_HOST"];
	$prefixHost = strpos($prefixHost, ":") ? implode(":", explode(":", $_SERVER["HTTP_HOST"], -1)) : $prefixHost;
	
	define("PROXY_PREFIX", "http" . (isset($_SERVER["HTTPS"]) ? "s" : "") . "://" . $prefixHost . $prefixPort . $_SERVER["SCRIPT_NAME"] . "?");
	define("CLEAR_LOGS", "CLEAR_LOGS_1234567765443288765");
	
	//Makes an HTTP request via cURL, using request data that was passed directly to this script.
	function makeRequest($url) {
		//Tell cURL to make the request using the brower's user-agent if there is one, or a fallback user-agent otherwise.
		$user_agent = $_SERVER["HTTP_USER_AGENT"];
		if (empty($user_agent)) {
			$user_agent = "Mozilla/5.0 (compatible; miniProxy)";
		}
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
		
		//Get ready to proxy the browser's request headers...
		$browserRequestHeaders = getallheaders();
		
		//...but let cURL set some headers on its own.
		removeKeys($browserRequestHeaders, array(
			"Host",
			"Content-Length",
			"Accept-Encoding" //Throw away the browser's Accept-Encoding header if any and let cURL make the request using gzip if possible.
		));
		
		curl_setopt($ch, CURLOPT_ENCODING, "");
		//Transform the associative array from getallheaders() into an
		//indexed array of header strings to be passed to cURL.
		
		$curlRequestHeaders = array();
		foreach ($browserRequestHeaders as $name => $value) {
			$curlRequestHeaders[] = $name . ": " . $value;
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $curlRequestHeaders);
		
		//Proxy any received GET/POST/PUT data.
		switch ($_SERVER["REQUEST_METHOD"]) {
			case "POST":
				curl_setopt($ch, CURLOPT_POST, true);
				//For some reason, $HTTP_RAW_POST_DATA isn't working as documented at
				//http://php.net/manual/en/reserved.variables.httprawpostdata.php
				//but the php://input method works. This is likely to be flaky
				//across different server environments.
				//More info here: http://stackoverflow.com/questions/8899239/http-raw-post-data-not-being-populated-after-upgrade-to-php-5-3
				//If the miniProxyFormAction field appears in the POST data, remove it so the destination server doesn't receive it.
				
				$postData = Array();
				parse_str(file_get_contents("php://input"), $postData);
				if (isset($postData["miniProxyFormAction"])) {
					unset($postData["miniProxyFormAction"]);
				}
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
				break;
			case "PUT":
				curl_setopt($ch, CURLOPT_PUT, true);
				curl_setopt($ch, CURLOPT_INFILE, fopen("php://input", "r"));
				break;
		}
		
		//Other cURL options.
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		//Set the request URL.
		curl_setopt($ch, CURLOPT_URL, $url);
		
		//Make the request.
		$response = curl_exec($ch);
		$responseInfo = curl_getinfo($ch);
		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		curl_close($ch);
		
		//Setting CURLOPT_HEADER to true above forces the response headers and body
		//to be output together--separate them.
		$responseHeaders = substr($response, 0, $headerSize);
		$responseBody = substr($response, $headerSize);
		
		return array("headers" => $responseHeaders, "body" => $responseBody, "responseInfo" => $responseInfo);
	} // function makeRequest

	//Converts relative URLs to absolute ones, given a base URL.
	//Modified version of code found at http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
	function rel2abs($rel, $base) {
		if (empty($rel)) $rel = ".";
		if (parse_url($rel, PHP_URL_SCHEME) != "" || strpos($rel, "//") === 0) return $rel; //Return if already an absolute URL
		if ($rel[0] == "#" || $rel[0] == "?") return $base.$rel; //Queries and anchors
		
		extract(parse_url($base)); //Parse base URL and convert to local variables: $scheme, $host, $path
		$path = isset($path) ? preg_replace("#/[^/]*$#", "", $path) : "/"; //Remove non-directory element from path
		if ($rel[0] == "/") $path = ""; //Destroy path if relative url points to root
		
		$port = isset($port) && $port != 80 ? ":" . $port : "";
		$auth = "";
		if (isset($user)) {
			$auth = $user;
			if (isset($pass)) {
				$auth .= ":" . $pass;
			}
			$auth .= "@";
		}
		
		$abs = "$auth$host$port$path/$rel"; //Dirty absolute URL
		for ($n = 1; $n > 0; $abs = preg_replace(array("#(/\.?/)#", "#/(?!\.\.)[^/]+/\.\./#"), "/", $abs, -1, $n)) {} //Replace '//' or '/./' or '/foo/../' with '/'
		return $scheme . "://" . $abs; //Absolute URL is ready.
	}
	
	//Proxify contents of url() references in blocks of CSS text.
	function proxifyCSS($css, $baseURL) {
		return preg_replace_callback(
			'/url\((.*?)\)/i',
			function($matches) use ($baseURL) {
				$url = $matches[1];
				//Remove any surrounding single or double quotes from the URL so it can be passed to rel2abs - the quotes are optional in CSS
				//Assume that if there is a leading quote then there should be a trailing quote, so just use trim() to remove them
				if (strpos($url, "'") === 0) {
					$url = trim($url, "'");
				}
				if (strpos($url, "\"") === 0) {
					$url = trim($url, "\"");
				}
				if (stripos($url, "data:") === 0) return "url(" . $url . ")"; //The URL isn't an HTTP URL but is actual binary data. Don't proxify it.
				return "url(" . PROXY_PREFIX . rel2abs($url, $baseURL) . ")";
			},
			$css
		);
	}
	
	//Proxify "srcset" attributes (normally associated with <img> tags.)
	function proxifySrcset($srcset, $baseURL) {
		$sources = array_map("trim", explode(",", $srcset)); //Split all contents by comma and trim each value
		$proxifiedSources = array_map(function($source) use ($baseURL) {
			$components = array_map("trim", str_split($source, strrpos($source, " "))); //Split by last space and trim
			$components[0] = PROXY_PREFIX . rel2abs(ltrim($components[0], "/"), $baseURL); //First component of the split source string should be an image URL; proxify it
			return implode($components, " "); //Recombine the components into a single source
			}, $sources);
		
		$proxifiedSrcset = implode(", ", $proxifiedSources); //Recombine the sources into a single "srcset"
		return $proxifiedSrcset;
	}

	//Extract and sanitize the requested URL, handling cases where forms have been rewritten to point to the proxy.
	if (isset($_POST["miniProxyFormAction"])) {
		$url = $_POST["miniProxyFormAction"];
		unset($_POST["miniProxyFormAction"]);
	} else {
		$queryParams = Array();
		parse_str($_SERVER["QUERY_STRING"], $queryParams);
		//If the miniProxyFormAction field appears in the query string, make $url start with its value, and rebuild the the query string without it.
		if (isset($queryParams["miniProxyFormAction"])) {
			$formAction = $queryParams["miniProxyFormAction"];
			unset($queryParams["miniProxyFormAction"]);
			$url = $formAction . '?' . http_build_query($queryParams);
		} else {
			$url = substr($_SERVER["REQUEST_URI"], strlen($_SERVER["SCRIPT_NAME"]) + 1);
		}
	}
	// Check for the &_= pattern and remove the same
	$queryrecvd = parse_url($url, PHP_URL_QUERY);
	if($queryrecvd === null) {
		// No query string received, check for the pattern		
		if(strpos($url, '&_=') !== false) {
			log_msg('Received Request for: ' . $url);
			// Eliminate the _ part
			$myArray = explode('&_=', $url);
			$url = $myArray[0];
			log_msg('Modified Request to: ' . $url);
		}
	}
	
	if (empty($url) || ($url === CLEAR_LOGS)) {
		// Clear the log file if requested.
		if($url === CLEAR_LOGS) {
			clear_log_file();
			//log_msg('Received Clear Logs Req: ' . $url);			
			//log_msg('About to redirect to ' . $_SERVER['PHP_SELF']);
			header('Location: '.$_SERVER['PHP_SELF']);
			die;
		}
		die("<html><head><title>miniProxy</title></head><body><h1>Welcome to miniProxy!</h1>miniProxy can be directly invoked like this: <a href=\"" . PROXY_PREFIX . "http://example.net/\">" . PROXY_PREFIX . "http://example.net/</a><br /><br />Or, you can simply enter a URL below:<br /><br /><form onsubmit=\"window.location.href='" . PROXY_PREFIX . "' + document.getElementById('site').value; return false;\"><input id=\"site\" type=\"text\" size=\"50\" /><input type=\"submit\" value=\"Proxy It!\" /></form><form onsubmit=\"window.location.href='" . PROXY_PREFIX . CLEAR_LOGS . "'; return false;\"><input type=\"submit\" value=\"Clear Logs\" /></form></body></html>");
	} else if (strpos($url, ":/") !== strpos($url, "://")) {
		//Work around the fact that some web servers (e.g. IIS 8.5) change double slashes appearing in the URL to a single slash.
		//See https://github.com/joshdick/miniProxy/pull/14
		$pos = strpos($url, ":/");
		$url = substr_replace($url, "://", $pos, strlen(":/"));
	}
	
	$scheme = parse_url($url, PHP_URL_SCHEME);
	if (empty($scheme)) {
		//Assume that any supplied URLs starting with // are HTTP URLs.
		if (strpos($url, "//") === 0) {
			$url = "http:" . $url;
		}
	} else if (!preg_match("/^https?$/i", $scheme)) {
		die('Error: Detected a "' . $scheme . '" URL. miniProxy exclusively supports http[s] URLs.');
	}
	
	//Validate the requested URL against the whitelist.
	$urlIsValid = count($whitelistPatterns) === 0;
	foreach ($whitelistPatterns as $pattern) {
		if (preg_match($pattern, $url)) {
			$urlIsValid = true;
			break;
		}
	}
	
	if (!$urlIsValid) {
		die("Error: The requested URL was disallowed by the server administrator.");
	}

	$response = makeRequest($url);
	$rawResponseHeaders = $response["headers"];
	$responseBody = $response["body"];
	$responseInfo = $response["responseInfo"];

	//If CURLOPT_FOLLOWLOCATION landed the proxy at a diferent URL than
	//what was requested, explicitly redirect the proxy there.
	$responseURL = $responseInfo["url"];
	
	if ($responseURL !== $url) {
		header("Location: " . PROXY_PREFIX . $responseURL, true);
		exit(0);
	}

	//A regex that indicates which server response headers should be stripped out of the proxified response.
	$header_blacklist_pattern = "/^Content-Length|^Transfer-Encoding|^Content-Encoding.*gzip/i";

	//cURL can make multiple requests internally (for example, if CURLOPT_FOLLOWLOCATION is enabled), and reports
	//headers for every request it makes. Only proxy the last set of received response headers,
	//corresponding to the final request made by cURL for any given call to makeRequest().
	$responseHeaderBlocks = array_filter(explode("\r\n\r\n", $rawResponseHeaders));
	$lastHeaderBlock = end($responseHeaderBlocks);
	$headerLines = explode("\r\n", $lastHeaderBlock);
	foreach ($headerLines as $header) {
		$header = trim($header);
		if (!preg_match($header_blacklist_pattern, $header)) {
			header($header);
		}
	}
	
	//Prevent robots from indexing proxified pages
	header('X-Robots-Tag: noindex, nofollow');

	if ($forceCORS) {
		//This logic is based on code found at: http://stackoverflow.com/a/9866124/278810
		//CORS headers sent below may conflict with CORS headers from the original response,
		//so these headers are sent after the original response headers to ensure their values
		//are the ones that actually end up getting sent to the browser.
		//Explicit [ $replace = true ] is used for these headers even though this is PHP's default behavior.
		//Allow access from any origin.
		
		header("Access-Control-Allow-Origin: *", true);
		header("Access-Control-Allow-Credentials: true", true);
		
		//Handle CORS headers received during OPTIONS requests.
		if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
			if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"])) {
				header("Access-Control-Allow-Methods: GET, POST, OPTIONS", true);
			}
			
			if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"])) {
				header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}", true);
			}
			//No further action is needed for OPTIONS requests.
			exit(0);
		}
	}

	$contentType = "";
	if (isset($responseInfo["content_type"])) $contentType = $responseInfo["content_type"];
	
	//This is presumably a web page, so attempt to proxify the DOM.
	if (stripos($contentType, "text/html") !== false) {
		//Attempt to normalize character encoding.
		$detectedEncoding = mb_detect_encoding($responseBody, "UTF-8, ISO-8859-1");
		if ($detectedEncoding) {
			$responseBody = mb_convert_encoding($responseBody, "HTML-ENTITIES", $detectedEncoding);
		}

		//Parse the DOM.
		$doc = new DomDocument();
		@$doc->loadHTML($responseBody);
		$xpath = new DOMXPath($doc);
		//Rewrite forms so that their actions point back to the proxy.
		
		foreach($xpath->query("//form") as $form) {
			$method = $form->getAttribute("method");
			$action = $form->getAttribute("action");
			//If the form doesn't have an action, the action is the page itself.
			//Otherwise, change an existing action to an absolute version.
			$action = empty($action) ? $url : rel2abs($action, $url);
			
			//Rewrite the form action to point back at the proxy.
			$form->setAttribute("action", rtrim(PROXY_PREFIX, "?"));
			//Add a hidden form field that the proxy can later use to retreive the original form action.
			$actionInput = $doc->createDocumentFragment();
			/* 
			 *	Manoj: To remove warnings while parsing caused due to &amp; in the action
			*/
			$actionInput->appendXML('<input type="hidden" name="miniProxyFormAction" value="' . str_replace(array("&amp;", "&"), array("&", "&amp;"), $action) . '" />');
			$form->appendChild($actionInput);
		}
		
		//Proxify <meta> tags with an 'http-equiv="refresh"' attribute.
		foreach ($xpath->query("//meta[@http-equiv]") as $element) {
			if (strcasecmp($element->getAttribute("http-equiv"), "refresh") === 0) {
				$content = $element->getAttribute("content");
				if (!empty($content)) {
					$splitContent = preg_split("/=/", $content);
					if (isset($splitContent[1])) {
						$element->setAttribute("content", $splitContent[0] . "=" . PROXY_PREFIX . rel2abs($splitContent[1], $url));
					}
				}
			}
		}
		
		//Profixy <style> tags.
		foreach($xpath->query("//style") as $style) {
			$style->nodeValue = proxifyCSS($style->nodeValue, $url);
		}
		
		//Proxify tags with a "style" attribute.
		foreach ($xpath->query("//*[@style]") as $element) {
			$element->setAttribute("style", proxifyCSS($element->getAttribute("style"), $url));
		}
		
		//Proxify "srcset" attributes in <img> tags.
		foreach ($xpath->query("//img[@srcset]") as $element) {
			$element->setAttribute("srcset", proxifySrcset($element->getAttribute("srcset"), $url));
		}
		
		//Proxify any of these attributes appearing in any tag.
		$proxifyAttributes = array("href", "src");
		foreach($proxifyAttributes as $attrName) {
			foreach($xpath->query("//*[@" . $attrName . "]") as $element) { //For every element with the given attribute...
				$attrContent = $element->getAttribute($attrName);
				if ($attrName == "href" && preg_match("/^(about|javascript|magnet|mailto):/i", $attrContent)) continue;
				$attrContent = rel2abs($attrContent, $url);
				$attrContent = PROXY_PREFIX . $attrContent;
				$element->setAttribute($attrName, $attrContent);
			}
		}
		
		//Attempt to force AJAX requests to be made through the proxy by
		//wrapping window.XMLHttpRequest.prototype.open in order to make
		//all request URLs absolute and point back to the proxy.
		//The rel2abs() JavaScript function serves the same purpose as the server-side one in this file,
		//but is used in the browser to ensure all AJAX request URLs are absolute and not relative.
		//Uses code from these sources:
		//http://stackoverflow.com/questions/7775767/javascript-overriding-xmlhttprequest-open
		//https://gist.github.com/1088850
		//TODO: This is obviously only useful for browsers that use XMLHttpRequest but
		//it's better than nothing.

		$head = $xpath->query("//head")->item(0);
		$body = $xpath->query("//body")->item(0);
		$prependElem = $head != NULL ? $head : $body;

		//Only bother trying to apply this hack if the DOM has a <head> or <body> element;
		//insert some JavaScript at the top of whichever is available first.
		//Protects against cases where the server sends a Content-Type of "text/html" when
		//what's coming back is most likely not actually HTML.
		//TODO: Do this check before attempting to do any sort of DOM parsing?
		
		if ($prependElem != NULL) {
			$scriptElem = $doc->createElement("script",
				'(function() {
					var proxyPrefix = "' . PROXY_PREFIX . '";
					if (window.XMLHttpRequest) {
						function parseURI(url) {
							var m = String(url).replace(/^\s+|\s+$/g, "").match(/^([^:\/?#]+:)?(\/\/(?:[^:@]*(?::[^:@]*)?@)?(([^:\/?#]*)(?::(\d*))?))?([^?#]*)(\?[^#]*)?(#[\s\S]*)?/);
							// authority = "//" + user + ":" + pass "@" + hostname + ":" port
							return (m ? {
								href : m[0] || "",
								protocol : m[1] || "",
								authority: m[2] || "",
								host : m[3] || "",
								hostname : m[4] || "",
								port : m[5] || "",
								pathname : m[6] || "",
								search : m[7] || "",
								hash : m[8] || ""
							} : null);
						}
					
						function rel2abs(base, href) { // RFC 3986
							function removeDotSegments(input) {
								var output = [];
								
								input.replace(/^(\.\.?(\/|$))+/, "")
									.replace(/\/(\.(\/|$))+/g, "/")
									.replace(/\/\.\.$/, "/../")
									.replace(/\/?[^\/]*/g, function (p) {
										if (p === "/..") {
											output.pop();
										} else {
											output.push(p);
										}
									});
								return output.join("").replace(/^\//, input.charAt(0) === "/" ? "/" : "");
							}
							
							href = parseURI(href || "");
							base = parseURI(base || "");
							return !href || !base ? null : (href.protocol || base.protocol) +
								(href.protocol || href.authority ? href.authority : base.authority) +
								removeDotSegments(href.protocol || href.authority || href.pathname.charAt(0) === "/" ? href.pathname : (href.pathname ? ((base.authority && !base.pathname ? "/" : "") + 
								base.pathname.slice(0, base.pathname.lastIndexOf("/") + 1) + href.pathname) : base.pathname)) +
								(href.protocol || href.authority || href.pathname ? href.search : (href.search || base.search)) +
								href.hash;
						}
						
						window.rel2abs = rel2abs; // Export rel2abs to be used in the post section for jquery.ajax handling
					
						var proxied = window.XMLHttpRequest.prototype.open;
						window.XMLHttpRequest.prototype.open = function() {
							if (arguments[1] !== null && arguments[1] !== undefined) {
								var url = arguments[1];

								if(url.substr(0, proxyPrefix.length) !== proxyPrefix) { // Prevent reProxification of alreday proxified URLs
									url = rel2abs("' . $url . '", url);
									url = "' . PROXY_PREFIX . '" + url;
									arguments[1] = url;
								}
							}
							return proxied.apply(this, [].slice.call(arguments));
						};
					}
				})();'
			); // createElement
			$scriptElem->setAttribute("type", "text/javascript");
			$prependElem->insertBefore($scriptElem, $prependElem->firstChild);
		}
		
		//Attempt to force AJAX requests made using $.ajax() to be made through the proxy by
		//wrapping jQuery.ajax in order to make
		//all request URLs absolute and point back to the proxy.
		
		$bodyElem = $xpath->query("//body")->item(0);
		if ($bodyElem != NULL) {
			$scriptElem = $doc->createElement("script",
				'(function() {					
					//console && console.log("jQuery:", jQuery, $, "proxy_prefix:", proxyPrefix );
					var proxyPrefix = "' . PROXY_PREFIX . '";
					if (typeof jQuery == "undefined") {
						; // Do nothing
					}
					else {
						if($.ajax){
							var oldAjax = $.ajax;
							$.ajax = function() {
								//console && console.trace("Step 1: ajax call", arguments.length, JSON.stringify([].slice.call(arguments)));
								if (arguments[0] !== null && arguments[0] !== undefined) {
									var url = arguments[0].url;

									if(url) {
										// Avoid re-proxification of URLs.
										if(url.substr(0, proxyPrefix.length) !== proxyPrefix) {
											url = rel2abs("' . $url . '", url);
											url = "' . PROXY_PREFIX . '" + url;
											arguments[0].url = url;
											//console && console.trace("Step 2: After patching url:", arguments.length, JSON.stringify([].slice.call(arguments)));
										}
									}
								}
								//return proxied.apply(this, [].slice.call(arguments));
								return oldAjax.apply($, [].slice.call(arguments));
							};
						}
					}

					var windOpen = window.open;
					window.open = function(strUrl, strWindowName, strWindowFeatures) {
						console.trace("w.O()", strUrl, strWindowName, strWindowFeatures);
						if(strUrl) {
							// Avoid re-proxification of URLs.
							if(strUrl.substr(0, proxyPrefix.length) !== proxyPrefix) {
								strUrl = rel2abs("' . $url . '", strUrl);
								strUrl = "' . PROXY_PREFIX . '" + strUrl;
							}
						}
						return windOpen(strUrl, strWindowName, strWindowFeatures);
					};
				})();'
			);
			$scriptElem->setAttribute("type", "text/javascript");
			$bodyElem->appendChild($scriptElem);
		}

		/*
			Removed signature string from html as it causes errors in the browser for some sites
		*/
		/*echo "<!-- Proxified page constructed by miniProxy -->\n" .*/
		echo $doc->saveHTML(); 
	} else if (stripos($contentType, "text/css") !== false) { //This is CSS, so proxify url() references.
		echo proxifyCSS($responseBody, $url);
	} else { //This isn't a web page or CSS, so serve unmodified through the proxy with the correct headers (images, JavaScript, etc.)
		header("Content-Length: " . strlen($responseBody));
		echo $responseBody;
	}
?>