<?php

use Framework\Alert;
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
	 * @throws Alert
	 */
	public function _main(Request $request, Response &$response)
	{
		$articles = self::loadCache('news');

		// load from Cubanet
		if (!is_array($articles)) {
			// get feed XML code from Cubanet
			$url = 'http://fetchrss.com/rss/5d7945108a93f8666f8b45675e4f5cb88a93f86d3d8b4567.xml';

			/** @var Feed $rss */
			$rss = Feed::loadRss($url);

			$articles = [];

			foreach ($rss->item as $item) {
				$articles[] = [
					'title' => strip_tags((string)$item->title),
					'link' => (string)$item->link,
					'pubDate' => date('m/d/Y H:i:s', (int)$item->timestamp),
					'description' => str_replace(['(Feed generated with FetchRSS)'], '', strip_tags((string)$item->description)),
					'category' => ['Noticias'],
					'author' => 'Cubanet.org',
				];
			}

			if (empty($articles)) {
				$this->simpleMessage(
					'Servicio no disponible',
					'El servicio Cubanet no se encuentra disponible en estos momentos. Intente luego y si el problema persiste contacte al soporte. Disculpe las molestias.',
					$response
				);

				return;
			}

			// save cache in the temp folder
			self::saveCache($articles, 'news');
		}

		// send data to the template
		$response->setCache(240);
		$response->setTemplate('stories.ejs', ['articles' => $articles], [__DIR__ . '/images/cubanet-logo.png']);
	}

	/**
	 * Load one specific news
	 *
	 * @param Request $request
	 * @param Response $response
	 *
	 * @throws Alert
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
		$cacheName = 'story_' . md5(preg_replace('/[^A-Za-z0-9]/', '', $query));
		$notice = self::loadCache($cacheName);

		// load from Cubanet
		if (!is_array($notice)) {
			Crawler::start($query);

			// search for title
			$title = Crawler::filter('header h1.entry-title');
			if ($title->count() > 0) $title = $title->text();
			else {
				$this->simpleMessage(
					'Artículo no encontrado',
					'El artículo que buscas no fue encontrado.',
					$response
				);
				return;
			}

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
					$imgName = Utils::randomHash() . '.' . pathinfo($imgUrl, PATHINFO_EXTENSION);
					$img = SHARED_PUBLIC_PATH . "content/cubanet/$imgName";
					try {
						$imgData = Crawler::get($imgUrl);
						file_put_contents($img, $imgData);
					} catch (Exception $e) {
						$imgName = '';
						$img = '';
					}
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
			self::saveCache($notice, $cacheName);
		}

		// get the image if exist
		$img = $notice['img'] ? SHARED_PUBLIC_PATH . "content/cubanet/{$notice['img']}" : false;
		$images = $img ? [$img] : [];
		$images[] = __DIR__ . '/images/cubanet-logo.png';

		// check the challenge
		Challenges::complete('read-cubanet', $request->person->id);

		// send data to the template
		$response->setCache();
		$response->setTemplate('story.ejs', $notice, $images);
	}

	/**
	 * Display news by category
	 *
	 * @param Request $request
	 * @param Response $response
	 *
	 * @throws Alert
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
		$cleanQuery = md5($this->clean($query));
		$cacheName = 'category_' . $cleanQuery;
		$articles = self::loadCache($cacheName);

		// load from Cubanet
		if (!is_array($articles)) {
			// Setup crawler
			Crawler::start('https://www.cubanet.org/tag/' . urlencode($cleanQuery));


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
			self::saveCache($articles, $cacheName);
		}

		// error if no articles were found for the tag
		if (empty($articles)) {
			$this->error($response, 'No hay resultados', 'Es extraño, pero no hemos encontrado resultados para esta categoría. Estamos revisando a ver que ocurre.');
			return;
		}

		// send data to the template
		$response->setTemplate('tags.ejs', ['articles' => $articles, 'category' => $query], [__DIR__ . '/images/cubanet-logo.png']);
	}

	/**
	 * Return an error message
	 *
	 * @param Response $response
	 * @param String $title
	 * @param String $desc
	 *
	 * @throws Alert
	 * @author salvipascual
	 */
	private function error(Response &$response, $title, $desc)
	{
		// display show error in the log
		error_log("[CUBANET] $title | $desc");

		// return error template
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
	 * @param String $text
	 * @param Integer $count
	 * @return String
	 * @author salvipascual
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
		if ($text[$count - 1] !== ' ') { // if not a space
			$new_pos = strrpos($cut_text, ' '); // find the space from the last character
			$cut_text = substr($text, 0, $new_pos);
		}

		return $cut_text . '...';
	}

	/**
	 * @param $name
	 *
	 * @return string
	 */
	public static function getCacheFileName($name): string
	{
		return TEMP_PATH . 'cache/cubanet_' . $name . '_' . date('Ymd') . '.tmp';
	}

	/**
	 * Load cache
	 *
	 * @param $name
	 * @param null $cacheFile
	 *
	 * @return bool|mixed
	 */
	public static function loadCache($name, &$cacheFile = null)
	{
		$data = false;
		$cacheFile = self::getCacheFileName($name);
		if (file_exists($cacheFile)) {
			$data = unserialize(file_get_contents($cacheFile));
		}
		return $data;
	}

	/**
	 * Save cache
	 *
	 * @param $name
	 * @param $data
	 * @param null $cacheFile
	 */
	public static function saveCache($data, $name = 'cache', &$cacheFile = null)
	{
		$cacheFile = self::getCacheFileName($name);
		file_put_contents($cacheFile, serialize($data));
	}

	/**
	 * @param string $header
	 * @param string $text
	 * @param Response $response
	 * @throws Alert
	 */
	public function simpleMessage(string $header, string $text, Response &$response): void
	{
		$response->setTemplate('message.ejs', [
			'header' => html_entity_decode($header),
			'text' => html_entity_decode($text)
		]);
	}
}