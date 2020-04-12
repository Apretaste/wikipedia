<?php

use Apretaste\Request;
use Apretaste\Response;
use Apretaste\Challenges;
use Framework\Utils;
use Framework\Crawler;

class Service
{

	/**
	 * Open the wikipedia service
	 *
	 * @param Request $request
	 * @param Response $response
	 *
	 * @return \Apretaste\Response
	 * @throws \Framework\Alert
	 * @author salvipascual
	 */
	public function _main(Request $request, Response &$response)
	{
		// do not allow blank searches
		if (empty($request->input->data->query)) {
			$response->setCache();
			return $response->setTemplate('home.ejs', []);
		}

		// find the right query in wikipedia
		$correctedQuery = $this->search($request->input->data->query);

		// message of the search is not valid
		if (empty($correctedQuery)) {
			$response->setCache();
			return $response->setTemplate('message.ejs', [
				'header' => 'Búsqueda no encontrada',
				'text' => 'Su búsqueda no fue encontrada en Wikipedia. Por favor modifique el texto e intente nuevamente.'
			]);
		}

		// get the HTML code for the page
		$page = $this->get(urlencode($correctedQuery));

		// get the home image
		$imageName = empty($page['images']) ? false : basename($page['images'][0]);

		// create a json object to send to the template
		$content = [
			'title' => utf8_encode($page['title'] ?? 'Wikipedia'),
			'body' => $page['body'],
			'image' => $imageName
		];

		// complete challenge
		Challenges::complete('search-in-wikipedia', $request->person->id);

		// send the response to the template
		$response->setCache('month');
		$response->setTemplate('wikipedia.ejs', $content, $page['images']);
	}

	/**
	 * Search in Wikipedia using OpenSearch
	 *
	 * @param  String: text to search
	 *
	 * @return Mixed: String OR false if article not found
	 * @throws \Framework\Alert
	 * @author salvipascual
	 */
	private function search($query)
	{
		// get the results based on your query
		$encodedQuery = urlencode($query);

		// get the results part as an array
		$page = Crawler::get("http://es.wikipedia.org/w/api.php?action=opensearch&search=$encodedQuery&limit=10&namespace=0&format=json");
		$results = json_decode($page)[1];

		// return corrected query or false
		if (isset($results[0])) {
			return utf8_decode($results[0]);
		}

		return false;
	}

	/**
	 * Get an article from wikipedia
	 *
	 * @param  String: text to search
	 *
	 * @return Mixed
	 * @throws \Framework\Alert
	 * @author salvipascual
	 */
	private function get($query)
	{
		// get content from cache
		$cache = TEMP_PATH . 'cache/wikipedia_' . md5($query) . date('Ym') . '.cache';
		if (file_exists($cache) && false) {
			$data = file_get_contents($cache);
			return unserialize($data);
		}

		// get the url
		$page = Crawler::get("http://es.wikipedia.org/w/api.php?action=query&prop=revisions&rvprop=content&format=xml&redirects=1&titles=$query&rvparse");
		$page = str_replace("/wiki/Wikipedia:Manual_de_estilo/P%C3%A1ginas_de_desambiguaci%C3%B3n", "", $page);

		// if data was found ...
		if (strpos($page, 'missing=""') === false) {
			// decode the text from UTF8 and convert to ISO, which supports Spanish
			if (mb_check_encoding($page, 'UTF8')) {
				$page = utf8_decode($page);
			}
			$page = html_entity_decode($page, ENT_COMPAT | ENT_HTML401, 'ISO-8859-1');

			// remove everything between the index and external links
			$mark = '<rev xml:space="preserve">';
			$page = substr($page, strpos($page, $mark) + strlen($mark));
			$page = str_replace('</rev></revisions></page></pages></query></api>', '', $page);
			$page = strip_tags($page, '<a><!--><!DOCTYPE><abbr><acronym><address><area><article><aside><b><base><basefont><bdi><bdo><big><blockquote><body><br><button><canvas><caption><center><cite><code><col><colgroup><command><datalist><dd><del><details><dfn><dialog><dir><div><dl><dt><em><embed><fieldset><figcaption><figure><font><footer><form><frame><frameset><head><header><h1> - <h6><hr><html><i><iframe><img><input><ins><kbd><keygen><label><legend><li><link><map><mark><menu><meta><meter><nav><noframes><noscript><object><ol><optgroup><option><output><p><param><pre><progress><q><rp><rt><ruby><s><samp><script><section><select><small><source><span><strike><strong><style><sub><summary><sup><table><tbody><td><textarea><tfoot><th><thead><time><title><tr><track><tt><u><ul><var><wbr><h2><h3>');
			$page = str_replace('oding="UTF-8"?>', '', $page);

			// removing the brackets []
			$page = preg_replace('/\[([^\[\]]++|(?R))*+\]/', '', $page);

			// remove the table of contents
			$mark = '<div id="toc" class="toc">';
			$p1 = strpos($page, $mark);
			if ($p1 !== false) {
				$p2 = strpos($page, '</div>', $p1);
				if ($p2 !== false) {
					$p2 = strpos($page, '</div>', $p2 + 1);
					$page = substr($page, 0, $p1) . substr($page, $p2 + 6);
				}
			}

			// remove external links
			$mark = '<span class="mw-headline" id="Enlaces_externos';
			$p = strpos($page, $mark);
			if ($p !== false) {
				$page = substr($page, 0, $p - 4);
			}

			// remove other stuff
			$page = str_replace('</api>', '', $page);
			$page = str_replace('<api>', '', $page);

			// remove references links
			$p = strpos($page, '<h2><span class="mw-headline" id="Referencias">');
			if ($p !== false) {
				$part = substr($page, $p);
				$part = strip_tags($part, '<li><ul><span><h2><h3>');
				$page = substr($page, 0, $p) . $part;
			}

			// clean the page
			$page = str_replace('>?</span>', '></span>', $page);
			$page = trim($page);

			if (! empty($page)) {
				// Build our DOMDocument, and load our HTML
				$doc = new DOMDocument();
				try {
					@$doc->loadHTML($page);
				} catch (Exception $e) {
				}

				// New-up an instance of our DOMXPath class
				$xpath = new DOMXPath($doc);

				// Find all elements whose class attribute has test2
				$elements = $xpath->query("//*[contains(@class,'thumb')]");

				// Cycle over each, remove attribute 'class'
				foreach ($elements as $element) {
					// Empty out the class attribute value
					$element->parentNode->removeChild($element);
				}

				// get the title from the response
				$nodes = $xpath->query("//th[contains(@class, 'cabecera')]");
				if (is_object($nodes) && $nodes->length > 0) {
					$title = htmlentities(trim($nodes->item(0)->textContent), ENT_COMPAT, 'UTF-8');
				} else {
					$title = urldecode(ucwords($query));
				}

				// make the suggestion smaller and separate it from the table
				$nodes = $xpath->query("//div[contains(@class, 'rellink')]");
				if (is_object($nodes) && $nodes->length > 0) {
					$nodes->item(0)->setAttribute('style', 'font-size:small;');
					$nodes->item(0)->appendChild($doc->createElement('br'));
					$nodes->item(0)->appendChild($doc->createElement('br'));
				}

				// make the table centered
				$nodes = $xpath->query("//table[contains(@class, 'infobox')]");
				if (is_object($nodes) && $nodes->length > 0) {
					$nodes->item(0)->setAttribute('border', '1');
					$nodes->item(0)->setAttribute('width', '100%');
					$nodes->item(0)->setAttribute('style', 'width:100%;');
				}

				// make the quotes takes the whole screen
				$nodes = $xpath->query("//table[contains(@class, 'wikitable')]");
				if (is_object($nodes)) {
					for ($i = 0; $i < $nodes->length; $i++) {
						$nodes->item($i)->setAttribute('width', '100%');
						$nodes->item($i)->setAttribute('style', 'table-layout:fixed; width:100%;');
					}
				}

				// remove all the noresize resources that makes the page wider
				$nodes = $xpath->query("//*[contains(@class, 'noresize')]");
				if (is_object($nodes)) {
					for ($i = 0; $i < $nodes->length; $i++) {
						$nodes->item($i)->parentNode->removeChild($nodes->item($i));
					}
				}

				// Load images
				$imagestags = $doc->getElementsByTagName('img');

				$images = [];
				if ($imagestags->length > 0) {
					foreach ($imagestags as $imgtag) {
						if (!is_object($imgtag)) {
							continue;
						}

						// get the full path to the image
						$imgsrc = $imgtag->getAttribute('src');
						if (strpos($imgsrc, '//') === 0) {
							$imgsrc = 'https:' . $imgsrc;
						}

						// ignore all images but the main image
						if (
							stripos($imgsrc, '/static/') !== false
							|| stripos($imgsrc, 'increase') !== false
							|| stripos($imgsrc, 'check') !== false
							|| stripos($imgsrc, 'mark') !== false
							|| stripos($imgsrc, 'emblem') !== false
							|| stripos($imgsrc, 'symbol_comment') !== false
							|| stripos($imgsrc, 'svg') !== false
							|| stripos($imgsrc, '.svg') !== false
						) {
							continue;
						}

						// save image file
						$filePath = TEMP_PATH . 'cache/' . Utils::randomHash() . '.jpg';
						$content = file_get_contents($imgsrc);
						file_put_contents($filePath, $content);

						// save the image in the array for the template
						$images[] = $filePath;
						break; // save only the first valid image
					}
				}

				// remove all the <a> linking images
				$nodes = $xpath->query("//a[contains(@class, 'image')]");
				if (is_object($nodes)) {
					for ($i = 0; $i < $nodes->length; $i++) {
						$nodes->item($i)->parentNode->removeChild($nodes->item($i));
					}
				}

				// Output the HTML of our container
				$page = $doc->saveHTML();

				// convert the links to onclick
				preg_match_all('/href="\/wiki\/(.*?)"/', $page, $matches);
				for ($i = 0, $iMax = count($matches[0]); $i < $iMax; $i++) {
					$onclick = 'wikisearch("' . urldecode($matches[1][$i]) . '")';
					$page = str_replace($matches[0][$i], "href='#!' onclick='$onclick'", $page);
				}

				// compress the returning code
				$page = preg_replace('/\s+/S', ' ', $page);

				// save the content that will go to the view
				$finalContent = [
						'title' => $title,
						'body' => base64_encode($page),
						'images' => $images
				];

				// create the cache and return
				file_put_contents($cache, serialize($finalContent));
				return $finalContent;
			}
		}

		return false;
	}
}
