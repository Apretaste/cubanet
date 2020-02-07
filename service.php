<?php

use Framework\Crawler;
use Apretaste\Request;
use Apretaste\Response;
use Apretaste\Challenges;
use Framework\Utils;

class Service
{

	/**
	 * Load the list of news
	 *
	 * @param Request $request
	 * @param Response $response
	 *
	 * @throws \FeedException
	 * @throws \Framework\Alert
	 */
	public function _main(Request $request, Response &$response)
	{
		// load from cache
		$articles = false;
		$cacheFile = TEMP_PATH . date('YmdH').'_cubanet_news.tmp';
		if (file_exists($cacheFile)) {
			$articles = unserialize(file_get_contents($cacheFile));
		}

		// load from Cubanet
		if (!is_array($articles)) {
			// get feed XML code from Cubanet
			//$page = trim(Utils::file_get_contents_curl("https://www.cubanet.org/?feed=rss2"));
			$url = 'http://fetchrss.com/rss/5d3df3968a93f8f3768b45675d3df1ff8a93f81a658b4567.xml';
			/** @var Feed $rss */
			$rss = Feed::loadRss($url);

			$articles = [];

			foreach ($rss->item as $item) {
				$articles[] = [
					'title' => strip_tags((string) $item->title),
					'link' => (string) $item->link,
					'pubDate' => date('m/d/Y H:i:s', (int) $item->timestamp),
					'description' => str_replace([
						'(Feed generated with FetchRSS)'
					], '', strip_tags((string) $item->description)),
					'category' => ['Noticias'],
					'author' => 'Cubanet.org',

				];
			}

			if (empty($articles)) {
				$this->simpleMessage(
					'Servicio no disponible',
					'El servicio Cubanet no se encuentra disponible en estos momentos. Intente luego y si el problema persiste contacte al soporte. Disculpe las molestias.'
				);

				return;
			}

			// save cache in the temp folder
			setlocale(LC_ALL, 'es_ES.UTF-8');
			file_put_contents($cacheFile, serialize($articles));
		}

		// send data to the template
		$response->setCache(240);
		$response->setLayout('cubanet.ejs');
		$response->setTemplate('stories.ejs', ['articles' => $articles], [__DIR__.'/images/cubanet-logo.png']);
	}

	/**
	 * Load one specific news
	 *
	 * @param Request $request
	 * @param Response $response
	 *
	 * @throws \Framework\Alert
	 */
	public function _historia(Request $request, Response &$response)
	{
		// do no allow empty entries
		if (empty($request->input->data->historia)) {
			$this->error($response, 'Búsqueda en blanco', 'Su búsqueda parece estar en blanco, debe decirnos que artículo quiere leer');
			return;
		}

		// load from cache
		$notice = false;
		$query = $request->input->data->historia;
		$cleanQuery = preg_replace('/[^A-Za-z0-9]/', '', $query);
		$cacheFile = TEMP_PATH . md5($cleanQuery).'_cubanet_story.tmp';
		if (file_exists($cacheFile) && false) {
			$notice = @unserialize(file_get_contents($cacheFile));
		}

		// load from Cubanet
		if (!is_array($notice)) {
			Crawler::start("https://www.cubanet.org/$query");

			// search for title
			$title = Crawler::filter('header h1.entry-title')->text();

			// get the intro
			$titleObj = Crawler::filter('header div>p');
			$intro = $titleObj->count() > 0 ? self::truncate($titleObj->text(), 160) : '';

			// get the images
			$imageObj = Crawler::filter('figure img.size-full');
			$imgUrl = '';
			$imgAlt = '';
			$img = '';
			$imgName = '';
			if ($imageObj->count() != 0) {
				$imgUrl = trim($imageObj->attr('src'));
				$imgAlt = trim($imageObj->attr('alt'));

				// get the image
				if (!empty($imgUrl)) {
					$imgName = Utils::randomHash().'.'.pathinfo($imgUrl, PATHINFO_EXTENSION);
					$img = IMG_PATH . "/cubanet/$imgName";
					file_put_contents($img, Crawler::get($imgUrl));
				}
			}

			// get the array of paragraphs of the body
			$paragraphs = Crawler::filter('div.entry-content p');
			$content = [];
			foreach ($paragraphs as $p) {
				$content[] = trim($p->textContent);
			}

			// create a json object to send to the template
			$notice = [
				'title' => $title,
				'intro' => $intro,
				'img' => $imgName,
				'imgAlt' => $imgAlt,
				'content' => $content
			];

			// save cache in the temp folder
			setlocale(LC_ALL, 'es_ES.UTF-8');
			file_put_contents($cacheFile, serialize($notice));
		}

		// get the image if exist
		$images = empty($notice['img']) ? [] : [$notice['img']];
		$notice['img'] = basename($notice['img']);

		$images[] = __DIR__.'/images/cubanet-logo.png';

		// send data to the template
		$response->setCache();
		$response->setLayout('cubanet.ejs');
		$response->setTemplate('story.ejs', $notice, $images);

		Challenges::complete('read-cubanet', $request->person->id);
	}

	/**
	 * Display news by category
	 *
	 * @param Request $request
	 * @param Response $response
	 *
	 * @throws \Framework\Alert
	 */
	public function _categoria(Request $request, Response &$response)
	{
		// do no allow empty entries
		if (empty($request->input->data->query)) {
			$this->error($response, 'Categoría en blanco', 'Su búsqueda parece estar en blanco, debe decirnos sobre que categoría desea leer');
			return;
		}

		// load from cache
		$articles = false;
		$query = $request->input->data->query;
		$cleanQuery = $this->clean($query);
		$fullPath = TEMP_PATH.date('Ymd').md5($cleanQuery).'_cubanet_category.tmp';
		if (file_exists($fullPath)) {
			$articles = @unserialize(file_get_contents($fullPath));
		}

		// load from Cubanet
		if (!is_array($articles)) {
			// Setup crawler
			Crawler::start('https://www.cubanet.org/tag/'.urlencode($cleanQuery));


			$articles = [];
			Crawler::filter('.grid_elements, .post-box')->each(function ($item) use (&$articles) {
				// get title, link and description
				$link = $item->filter('h2 > a')->attr('href');
				$title = str_replace('Vínculo Permanente a ', '', $item->filter('h2 > a')->attr('title'));
				$description = $item->filter('.content_wrapper, .entry-content, p')->text();
				$description = trim(strip_tags($description));
				$description = html_entity_decode($description);
				$description = self::truncate($description, 160);
				$description = preg_replace('/\s+/', ' ', $description);

				// add tag entry to the list
				$articles[] = [
					'title' => $title,
					'link' => $link,
					'description' => $description
				];
			});

			// save cache in the temp folder
			setlocale(LC_ALL, 'es_ES.UTF-8');
			file_put_contents($fullPath, serialize($articles));
		}

		// error if no articles were found for the tag
		if (empty($articles)) {
			$this->error($response, 'No hay resultados', 'Es extraño, pero no hemos encontrado resultados para esta categoría. Estamos revisando a ver que ocurre.');
			return;
		}

		// send data to the template
		//$response->setCache(240);
		$response->setLayout('cubanet.ejs');
		$response->setTemplate('tags.ejs', ['articles' => $articles, 'category' => $query], [__DIR__.'/images/cubanet-logo.png']);
	}

	/**
	 * Return an error message
	 *
	 * @param Response $response
	 * @param String $title
	 * @param String $desc
	 *
	 * @throws \Framework\Alert
	 * @author salvipascual
	 */
	private function error(Response &$response, $title, $desc)
	{
		// display show error in the log
		error_log("[CUBANET] $title | $desc");

		// return error template
		$response->setLayout('cubanet.ejs');

		$response->setTemplate('message.ejs', ['header' => $title, 'text' => $desc]);
	}

	/**
	 * Change special characters into regulars and make a lowercase string
	 *
	 * @param String $query
	 *
	 * @return String
	 * @author salvipascual
	 */
	private function clean($query)
	{
		$source = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿŔŕ';
		$modified = 'aaaaaaaceeeeiiiidnoooooouuuuybsaaaaaaaceeeeiiiidnoooooouuuyybyRr';
		$query = utf8_decode($query);
		$query = strtr($query, utf8_decode($source), $modified);
		$query = strtolower($query);
		$query = str_replace(' ', '-', $query);

		return utf8_encode($query);
	}

	/**
	 * Cut a string without breaking words
	 *
	 * @author salvipascual
	 * @param String $text
	 * @param Integer $count
	 * @return String
	 */
	public static function truncate($text, $count)
	{
		// do not cut shorter strings
		if (strlen($text) <= $count) {
			return $text;
		}

		// cut the string
		$cut_text = substr($text, 0, $count);

		// cut orphan words
		if ($text{$count - 1} != ' ') { // if not a space
			$new_pos 	= strrpos($cut_text, ' '); // find the space from the last character
			$cut_text 	= substr($text, 0, $new_pos);
		}

		return $cut_text . '...';
	}
}
