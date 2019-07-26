<?php

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class CubanetService extends ApretasteService
{
	/**
	 * Load the list of news
	 *
	 * @param Request $request
	 * @param Response $response
	 */
	public function _main(Request $request, Response &$response)
	{
		// load from cache
		$articles = false;
		$cacheFile = Utils::getTempDir() . date("YmdH") . '_cubanet_news.tmp';
		if(file_exists($cacheFile)) $articles = unserialize(file_get_contents($cacheFile));

		// load from Cubanet
		if(!is_array($articles)){
			// get feed XML code from Cubanet
			$page = trim(Utils::file_get_contents_curl("https://www.cubanet.org/feed"));

			if (empty($page))
            {
                $this->simpleMessage(
                    'Servicio no disponible',
                    'El servicio Cubanet no se encuentra disponible en estos momentos. Intente luego y si el problema persiste contacte al soporte. Disculpe las molestias.');
                return;
            }

			//tuve que usar simplexml debido a que el feed provee los datos dentro de campos cdata
            libxml_use_internal_errors(true);
			$content = @simplexml_load_string($page, null, LIBXML_NOCDATA);
            if (!$content) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $this->simpleMessage(
                    'Servicio no disponible',
                    'El servicio Cubanet no se encuentra disponible en estos momentos. Intente luego y si el problema persiste contacte al soporte. Disculpe las molestias.');
                return;
            }

			$articles = [];
			foreach ($content->channel->item as $item) {
				// get title, link and description
				$title = str_replace("'", "", $item->title);
				$link = str_replace('https://www.cubanet.org/', "", $item->link);
				$description = $item->description;
				$description = trim(strip_tags($description));
				$description = html_entity_decode($description);
				$description = php::truncate($description, 160);

				// get formatted date 
				$pubDate = $item->pubDate;
				$fecha = strftime("%B %d, %Y.",strtotime($pubDate)); 
				$hora = date_format((new DateTime($pubDate)),'h:i a');
				$pubDate = $fecha." ".$hora;

				// get author
				$dc = $item->children("http://purl.org/dc/elements/1.1/");
				$author = strval($dc->creator);

				// get list of categories
				$category = [];
				foreach ($item->category as $currCategory) {
					$category[] = strval($currCategory);
				}

				// add article to the list
				$articles[] = [
					"title"	=> $title,
					"link" => $link,
					"pubDate" => $pubDate,
					"description" => $description,
					"category" => $category,
					"author" => $author,
				];
			}

			// save cache in the temp folder
			setlocale(LC_ALL, 'es_ES.UTF-8');
			file_put_contents($cacheFile, serialize($articles));
		}

		// send data to the template
		$response->setCache(240);
		$response->setLayout('cubanet.ejs');
		$response->setTemplate("stories.ejs", ["articles" => $articles], [Utils::getPathToService("cubanet")."/images/cubanet-logo.png"]);
	}

	/**
	 * Load one specific news
	 *
	 * @param Request $request
	 * @param Response $response
	 */
	public function _historia(Request $request, Response &$response)
	{
		// do no allow empty entries
		if (empty($request->input->data->historia)) {
			return $this->error($response, "Búsqueda en blanco", "Su búsqueda parece estar en blanco, debe decirnos que artículo quiere leer");
		}

		// load from cache
		$notice = false;
		$query = $request->input->data->historia;
		$cleanQuery = preg_replace('/[^A-Za-z0-9]/', '', $query);
		$cacheFile = Utils::getTempDir() . md5($cleanQuery) . '_cubanet_story.tmp';
		if(file_exists($cacheFile)) $notice = @unserialize(file_get_contents($cacheFile));

		// load from Cubanet
		if(!is_array($notice)){
			// create a new client
			$client = new Client();
			$guzzle = $client->getClient();
			$client->setClient($guzzle);
			$crawler = $client->request('GET', "https://www.cubanet.org/$query");

			// search for title
			$title = $crawler->filter('header h1.entry-title')->text();

			// get the intro
			$titleObj = $crawler->filter('header div>p');
			$intro = $titleObj->count() > 0 ? php::truncate($titleObj->text(), 160) : "";

			// get the images
			$imageObj = $crawler->filter('figure img.size-full');
			$imgUrl	= "";
			$imgAlt	= "";
			$img = "";
			if ($imageObj->count() != 0) {
				$imgUrl = trim($imageObj->attr("src"));
				$imgAlt = trim($imageObj->attr("alt"));

				// get the image
				if (!empty($imgUrl)) {
					$imgName = Utils::generateRandomHash() . "." . pathinfo($imgUrl, PATHINFO_EXTENSION);
					$img = \Phalcon\DI\FactoryDefault::getDefault()->get('path')['root'] . "/temp/$imgName";
					file_put_contents($img, file_get_contents($imgUrl));
				}
			}

			// get the array of paragraphs of the body
			$paragraphs = $crawler->filter('div.entry-content p');
			$content = [];
			foreach ($paragraphs as $p) {
				$content[] = trim($p->textContent);
			}

			// create a json object to send to the template
			$notice = [
				"title"	=> $title,
				"intro"	=> $intro,
				"img" => $img,
				"imgAlt" => $imgAlt,
				"content" => $content
			];

			// save cache in the temp folder
			setlocale(LC_ALL, 'es_ES.UTF-8');
			file_put_contents($cacheFile, serialize($notice));
		}

		// get the image if exist
		$images = empty($notice['img']) ? [] : [$notice['img']];
		$notice['img'] = basename($notice['img']);

		$images[] = Utils::getPathToService("cubanet")."/images/cubanet-logo.png";

		// send data to the template
		$response->setCache();
		$response->setLayout('cubanet.ejs');
		$response->setTemplate("story.ejs", $notice, $images);
	}

	/**
	 * Display news by category
	 *
	 * @param Request $request
	 * @param Response $response
	 */
	public function _categoria(Request $request, Response &$response)
	{
		// do no allow empty entries
		if (empty($request->input->data->query)) {
			return $this->error($response, "Categoría en blanco", "Su búsqueda parece estar en blanco, debe decirnos sobre que categoría desea leer");
		}

		// load from cache
		$articles = false;
		$query = $request->input->data->query;
		$cleanQuery = $this->clean($query);
		$fullPath = Utils::getTempDir() . date("Ymd") . md5($cleanQuery) . '_cubanet_category.tmp';
		if(file_exists($fullPath)) $articles = @unserialize(file_get_contents($fullPath));

		// load from Cubanet
		if(!is_array($articles)){
			// Setup crawler
			$client = new Client();
			$crawler = $client->request('GET', "https://www.cubanet.org/tag/". urlencode($cleanQuery));

			$articles = [];
			$crawler->filter('.grid_elements, .post-box')->each(function($item) use (&$articles){
				// get title, link and description
				$link = $item->filter('h2 > a')->attr("href");
				$title = str_replace("Vínculo Permanente a ","",$item->filter('h2 > a')->attr("title"));
				$description = $item->filter('.content_wrapper, .entry-content, p')->text();
				$description = trim(strip_tags($description));
				$description = html_entity_decode($description);
				$description = php::truncate($description, 160);
				$description = preg_replace('/\s+/', ' ', $description);

				// add tag entry to the list
				$articles[] = [
					"title" => $title,
					"link" => $link,
					"description" => $description
				];
			});

			// save cache in the temp folder
			setlocale(LC_ALL, 'es_ES.UTF-8');
			file_put_contents($fullPath, serialize($articles));
		}

		// error if no articles were found for the tag
		if (empty($articles)) {
			return $this->error($response, "No hay resultados", "Es extraño, pero no hemos encontrado resultados para esta categoría. Estamos revisando a ver que ocurre.");
		}

		// send data to the template
		$response->setCache(240);
		$response->setLayout('cubanet.ejs');
		$response->setTemplate("tags.ejs", ["articles" => $articles, "category" => $query], [Utils::getPathToService("cubanet")."/images/cubanet-logo.png"]);
	}

	/**
	 * Return an error message
	 *
	 * @author salvipascual
	 * @param Response $response
	 * @param String $title 
	 * @param String $desc 
	 * @return Response
	 */
	private function error(Response $response, $title, $desc)
	{
		// display show error in the log
		error_log("[CUBANET] $title | $desc");

		// return error template
		$response->setLayout('cubanet.ejs');
		return $response->setTemplate('message.ejs', ["header" => $title, "text" => $desc]);
	}

	/**
	 * Change special characters into regulars and make a lowercase string
	 *
	 * @author salvipascual
	 * @param String $query
	 * @return String
	 */
	private function clean($query)
	{
		$source = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿŔŕ';
		$modified = 'aaaaaaaceeeeiiiidnoooooouuuuybsaaaaaaaceeeeiiiidnoooooouuuyybyRr';
		$query = utf8_decode($query);
		$query = strtr($query, utf8_decode($source), $modified);
		$query = strtolower($query);
		$query = str_replace(" ", "-", $query);
		return utf8_encode($query);
	}
}