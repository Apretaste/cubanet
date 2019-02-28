<?php
	use Goutte\Client;
	use Symfony\Component\DomCrawler\Crawler;

	class Cubanet extends Service
	{
		/**
		 * Function executed when the service is called
		 *
		 * @param Request
		 * @return Response
		 * */
		public function _main(Request $request)
		{
			$response = new Response();
			$response->setEmailLayout('cubanet.tpl');
			$response->setResponseSubject("Noticias de hoy");
			$response->createFromTemplate("allStories.tpl", $this->allStories());
			return $response;
		}

		/**
		 * Call to show the news
		 *
		 * @param Request
		 * @return Response
		 * */
		public function _buscar(Request $request)
		{
			// no allow blank entries
			if (empty($request->query))
			{
				$response = new Response();
				$response->setEmailLayout('cubanet.tpl');
				$response->setResponseSubject("Busqueda en blanco");
				$response->createFromText("Su busqueda parece estar en blanco, debe decirnos sobre que tema desea leer");
				return $response;
			}

			// search by the query
			try {
				$articles = $this->searchArticles($request->query);
			} catch (Exception $e) {
				return $this->respondWithError();
			}

			// error if the searche return empty
			if(empty($articles))
			{
				$response = new Response();
				$response->setEmailLayout('cubanet.tpl');
				$response->setResponseSubject("Su busqueda no genero resultados");
				$response->createFromText("Su busqueda <b>{$request->query}</b> no generó ningún resultado. Por favor cambie los términos de búsqueda e intente nuevamente.");
				return $response;
			}

			$responseContent = array(
				"articles" => $articles,
				"search" => $request->query
			);

			$response = new Response();
			$response->setEmailLayout('cubanet.tpl');
			$response->setResponseSubject("Buscar: " . $request->query);
			$response->createFromTemplate("searchArticles.tpl", $responseContent);
			return $response;
		}

		/**
		 * Call to show the news
		 *
		 * @param Request
		 * @return Response
		 * */
		public function _historia(Request $request)
		{
			// no allow blank entries
			if (empty($request->query))
			{
				$response = new Response();
				$response->setEmailLayout('cubanet.tpl');
				$response->setResponseSubject("Busqueda en blanco");
				$response->createFromText("Su busqueda parece estar en blanco, debe decirnos que articulo quiere leer");
				return $response;
			}

			// send the actual response
			try {
				$responseContent = $this->story($request->query);
			} catch (Exception $e) {
				return $this->respondWithError();
			}

			// get the image if exist
			$images = array();
			if( ! empty($responseContent['img']))
			{
				$images = array($responseContent['img']);
			}

			$response = new Response();
			$response->setCache();
			$response->setEmailLayout('cubanet.tpl');
			$response->setResponseSubject("La historia que usted pidio");
			$response->createFromTemplate("story.tpl", $responseContent, $images);
			return $response;
		}

		/**
		 * Call list by categoria
		 *
		 * @param Request
		 * @return Response
		 * */
		public function _categoria(Request $request)
		{
			if (empty($request->query))
			{
				$response = new Response();
				$response->setEmailLayout('cubanet.tpl');
				$response->setResponseSubject("Categoria en blanco");
				$response->createFromText("Su busqueda parece estar en blanco, debe decirnos sobre que categoría desea leer");
				return $response;
			}

			$responseContent = array(
				"articles" => $this->listArticles($request->query)["articles"],
				"category" => $request->query
			);

			$response = new Response();
			$response->setEmailLayout('cubanet.tpl');
			$response->setResponseSubject("Categoria: ".$request->query);
			$response->createFromTemplate("catArticles.tpl", $responseContent);
			return $response;
		}

		/**
		 * Search stories
		 *
		 * @param String
		 * @return Array
		 * */
		private function searchArticles($query)
		{
			// Setup crawler
			$client = new Client();
			$url = "https://www.cubanet.org/?s=".urlencode($query);
			$crawler = $client->request('GET', $url);

			// Collect saearch by term
			$articles = array();
 			$crawler->filter('ul.entry-list.isotop-item.clearfix li.element')->each(function($item, $i) use (&$articles)
 			{
 				// only allow news, no media or gallery
 				if($item->filter('.ico')->count()>0) return;

 				// get data from each row
 				$title = $item->filter('h4.entry-title a')->text();
 				$date = $item->filter('header span.entry-date')->text();
 				$description = $item->filter('p')->text();
 				$link = $item->filter('a.more-link')->attr("href");

 				// store list of articles
 				$articles[] = array(
 					"pubDate" => $date,
 					"description" => $description,
 					"title"	=> $title,
 					"link" => $link
 				);
 			});


			return $articles;
		}

		/**
		 * Get the array of news by content
		 *
		 * @param String
		 * @return Array
		 */
		private function listArticles($query)
		{
			$page = file_get_contents("https://www.cubanet.org/feed");
			//tuve que usar simplexml debido a que el feed provee los datos dentro de campos cdata
			$content = simplexml_load_string($page, null, LIBXML_NOCDATA);

			$articles = array();
			foreach ($content->channel->item as $item) {
				// if category matches, add to list of articles
				foreach ($item->category as $cat){
					if (strtoupper($cat) == strtoupper($query)){
						// get all parameters
						$title = $item->title;
						$link = $this->urlSplit($item->link);
						$description = $item->description;
						$pubDate = $item->pubDate;
						$dc = $item->children("http://purl.org/dc/elements/1.1/");
		    			$author = $dc->creator;

						$articles[] = array(
							"title" => $title,
							"link" => $link,
							"pubDate" => $pubDate,
							"description" => $description,
							"author" => $author
						);
					}
				}
			}

			// Return response content
			return array("articles" => $articles);
		}

		/**
		 * Get all stories from a query
		 *
		 * @return Array
		 */
		private function allStories()
		{
			$page = file_get_contents("https://www.cubanet.org/feed");
			//tuve que usar simplexml debido a que el feed provee los datos dentro de campos cdata
			$content = simplexml_load_string($page, null, LIBXML_NOCDATA);

			$articles = array();
			foreach ($content->channel->item as $item) {
				// get all parameters
				$title = $item->title;
				$link = $this->urlSplit($item->link);
				$description = $item->description;
				$pubDate = $item->pubDate;
				$dc = $item->children("http://purl.org/dc/elements/1.1/");
    			$author = $dc->creator;
				$category = array();
				foreach ($item->category as $currCategory)
				{
					$category[] = $currCategory;
				}
				$categoryLink = array();

				$articles[] = array(
					"title" => $title,
					"link" => $link,
					"pubDate" => $pubDate,
					"description" => $description,
					"category" => $category,
					"categoryLink" => $categoryLink,
					"author" => $author
				);
			}

			// return response content
			return array("articles" => $articles);
		}

		/**
		 * Get an specific news to display
		 *
		 * @param String
		 * @return Array
		 */
		private function story($query)
		{
			// create a new client
			$client = new Client();
			$guzzle = $client->getClient();
			$client->setClient($guzzle);

			// create a crawler
			$crawler = $client->request('GET', "https://www.cubanet.org/$query");

			// search for title
			$title = $crawler->filter('header h1.entry-title')->text();

			// get the intro
			$titleObj = $crawler->filter('header div>p');
			$intro = $titleObj->count()>0 ? $titleObj->text() : "";

			// get the images
			$imageObj = $crawler->filter('figure img.size-full');
			$imgUrl = ""; $imgAlt = ""; $img = "";
			if ($imageObj->count() != 0)
			{
				$imgUrl = trim($imageObj->attr("src"));
				$imgAlt = trim($imageObj->attr("alt"));

				// get the image
				if ( ! empty($imgUrl))
				{
					$imgName = $this->utils->generateRandomHash() . "." . pathinfo($imgUrl, PATHINFO_EXTENSION);
					$img = \Phalcon\DI\FactoryDefault::getDefault()->get('path')['root'] . "/temp/$imgName";
					file_put_contents($img, file_get_contents($imgUrl));
				}
			}

			// get the array of paragraphs of the body
			$paragraphs = $crawler->filter('div.entry-content p');
			$content = array();
			foreach ($paragraphs as $p)
			{
				$content[] = trim($p->textContent);
			}

			// create a json object to send to the template
			return array(
				"title" => $title,
				"intro" => $intro,
				"img" => $img,
				"imgAlt" => $imgAlt,
				"content" => $content,
				"url" => "https://www.cubanet.org/$query"
			);
		}

		/**
		 * Get the link to the news starting from the /content part
		 *
		 * @param String
		 * @return String
		 *
		 */
		private function urlSplit($url)
		{
			$url = explode("/", trim($url));
			unset($url[0]);
			unset($url[1]);
			unset($url[2]);
			return implode("/", $url);
		}

		/**
		 * Return a generic error email, usually for try...catch blocks
		 *
		 * @auhor salvipascual
		 * @return Respose
		 */
		private function respondWithError()
		{
			error_log("WARNING: ERROR ON SERVICE CUBANET");

			$response = new Response();
			$response->setEmailLayout('cubanet.tpl');
			$response->setResponseSubject("Error en peticion");
			$response->createFromText("Lo siento pero hemos tenido un error inesperado. Enviamos una peticion para corregirlo. Por favor intente nuevamente mas tarde.");
			return $response;
		}
	}
?>
