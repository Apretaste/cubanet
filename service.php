<?php

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;


class Service {


  /**
   * Function executed when the service is called
   *
   * @param Request $request
   * @param Response $response
   *
   **/
  public function _main(Request $request, Response &$response)
  {
    $articles = false;
    $pathToService = Utils::getPathToService($response->serviceName);
    $cacheFile = Utils::getTempDir() . date("YmdH") . 'main_cubanet.tmp';
   
    if(file_exists($cacheFile)) $articles = unserialize(file_get_contents($cacheFile));

    if(!is_array($articles)){
       $page = Utils::file_get_contents_curl("https://www.cubanet.org/feed");

      //tuve que usar simplexml debido a que el feed provee los datos dentro de campos cdata
      $content = simplexml_load_string($page, null, LIBXML_NOCDATA);

      $articles = [];
      foreach ($content->channel->item as $item) {
        // get all parameters
        $title       = $item->title . "";
        $link        = $this->urlSplit($item->link . "");
        $description = $item->description . "";
        $pubDate     = $item->pubDate . "";
        setlocale(LC_ALL, 'es_ES.UTF-8');
				$fecha = strftime("%B %d, %Y.",strtotime($pubDate)); 
				$hora = date_format((new DateTime($pubDate)),'h:i a');
				$pubDate = $fecha." ".$hora;
        $dc          = $item->children("http://purl.org/dc/elements/1.1/");
        $author      = $dc->creator . "";
        $category    = [];
        foreach ($item->category as $currCategory) {
          $category[] = $currCategory . "";
        }
        $categoryLink = [];

        $articles[] = [
          "title"        => $title,
          "link"         => $link,
          "pubDate"      => $pubDate,
          "description"  => "$description",
          "category"     => $category,
          "categoryLink" => $categoryLink,
          "author"       => $author,
        ];
      }
      // save cache in the temp folder
      file_put_contents($cacheFile, serialize($articles));
    }
    $response->setCache(240);
    $response->setLayout('cubanet.ejs');
    $response->setTemplate("allStories.ejs", ["articles" => $articles], ["$pathToService/images/cubanet-logo.png"]);
  }

  /**
   * Call to show the news
   *
   * @param Request $request
   * @param Response $response
   *
   * @return void
   **/
  public function _buscar(Request $request, Response &$response) {
    // no allow blank entries
    if (empty($request->input->data->searchQuery)) {
      $this->respondText($response, [
        "title"       => "Busqueda en blanco",
        "description" => "Su b&uacute;squeda parece estar en blanco, debe decirnos sobre que tema desea leer",
      ]);

      return;
    }

    // search by the query
    try {
      $articles = $this->searchArticles($request->input->data->searchQuery);
    } catch (Exception $e) {
      $this->respondWithError($response);

      return;
    }

    // error if the searche return empty
    if (empty($articles)) {
      $this->respondText($response, [
        "title"       => "Su busqueda no produjo resultados",
        "description" => "Su busqueda <b>{$request->input->data->searchQuery}</b> no gener&oacute; ning&uacute;n resultado. Por favor cambie los t&eacute;rminos de b&uacute;squeda e intente nuevamente.",
      ]);

      return;
    }

    $responseContent = [
      "articles" => $articles,
      "search"   => $request->input->data->searchQuery,
    ];

    $response->setLayout('cubanet.ejs');
    $response->setTemplate("searchArticles.ejs", $responseContent);

    return;
  }

  /**
   * Call to show the news
   *
   * @param Request
   *
   * @return void
   **/
  public function _historia(Request $request, Response &$response) {
    // no allow blank entries
    if (empty($request->input->data->historia)) {
      $this->respondText($response, [
        "title"       => "Busqueda en blanco",
        "description" => "Su busqueda parece estar en blanco, debe decirnos que articulo quiere leer",
      ]);

      return;
    }

    // send the actual response
    try {
      $responseContent = $this->story($request->input->data->historia);
    } catch (Exception $e) {
      $this->respondWithError($response);

      return;
    }

    // get the image if exist
    $images = [];
    if (!empty($responseContent['img'])) {
      $images = [$responseContent['img']];
    }

    if(isset($request->input->data->category)) 
				$responseContent['backButton'] = "{'command':'CUBANET CATEGORIA', 'data':{'category':'{$request->input->data->category}'}}";
			else
				$responseContent['backButton'] = "{'command':'CUBANET'}";

    $response->setCache();
    $response->setLayout('cubanet.ejs');
    $response->setTemplate("story.ejs", $responseContent, $images);

  }

  /**
   * Call list by categoria
   *
   * @param Request
   * @param Response
   *
   * @return void
   * */
  public function _categoria(Request $request, Response &$response) {
    
    if (empty($request->input->data->query)) {
      $this->respondText($response, [
        "title"       => "Categoria en blanco",
        "description" => "Su busqueda parece estar en blanco, debe decirnos sobre que categor&iacute;a desea leer",
      ]);

      return;
    }

    $responseContent = [
      "articles" => $this->listArticles($request->input->data->query),
      "category" => $request->input->data->query,
    ];
    
    $response->setCache(240);
    $response->setLayout('cubanet.ejs');
    $response->setTemplate("searchArticles.ejs", $responseContent);
  }

  /**
   * Search stories
   *
   * @param String
   *
   * @return array
   **/
  private function searchArticles($query) {
    // Setup crawler
    $client  = new Client();
    $url     = "https://www.cubanet.org/?s=" . urlencode($query);
    $crawler = $client->request('GET', $url);
    $articles = false;
    $cacheFile = Utils::getTempDir() . date("YmdH") .$query. 'cubanet.tmp';
    
    if(file_exists($cacheFile)) $articles = unserialize(file_get_contents($cacheFile));

    if(!is_array($articles)){
    // Collect saearch by term
    $articles = [];
    $crawler->filter('ul.entry-list.isotop-item.clearfix li.element')
            ->each(function ($item, $i) use (&$articles) {
              // only allow news, no media or gallery
              if ($item->filter('.ico')->count() > 0) {
                return;
              }

              // get data from each row
              $title       = $item->filter('h4.entry-title a')->text();
              $date        = $item->filter('header span.entry-date')->text();
              $description = $item->filter('p')->text();
              $link        = $item->filter('a.more-link')->attr("href");

              // store list of articles
              $articles[] = [
                "pubDate"     => $date,
                "description" => $description,
                "title"       => $title,
                "link"        => $link,
              ];
            });


    return $articles;
  }
}

  /**
   * Get the array of news by content
   *
   * @param String
   *
   * @return array
   */
  private function listArticles($query) {
    
      // load from cache file if exists
      $temp = Utils::getTempDir();
      $fileName = date("YmdH") . md5($query) . 'category_cubanet.tmp';
      $fullPath = "$temp/$fileName";
  
      $articles = false;
  
      if(file_exists($fullPath)) $articles = @unserialize(file_get_contents($fullPath));
  
      if(!is_array($articles)){
        // Setup crawler
        $client = new Client();
        $articles = [];
        $type = "tag";
      
        for ($i=1; $i < 3; $i++) {
          $url = "https://www.cubanet.org/$type/". urlencode(str_replace(" ","-",strtolower($this->noSpecialCharacter($query))))."/page/$i/";
          $crawler = $client->request('GET', $url);
          

          if($crawler->filter('.grid_elements, .post-box')->count()==0){
            $type = "categoria";
            $i--;
            continue;
          }
          
          $crawler->filter('.grid_elements, .post-box')->each(function($item) use (&$articles, $temp, $client){
            //if($item->filter('.audio-watermark, .video-watermark')->count()==0){
              $link = $item->filter('h2 > a')->attr("href");
              $title = str_replace("Vínculo Permanente a ","",$item->filter('h2 > a')->attr("title"));
  
              $tmpFile = "$temp/article_".md5($title)."_cbnet.html";
  
              if(!file_exists($tmpFile)){
                $html = file_get_contents($link);
                file_put_contents($tmpFile, $html);
              }
  
              $author = (new Crawler(file_get_contents($tmpFile)))->filter('.author-link > a, .author-name')->text();
              $pubDate = (new Crawler(file_get_contents($tmpFile)))->filter('.date')->text();
              $info = explode(",",$pubDate);
              $day = trim((explode("de",$info[1]))[0]);
              $month = trim((explode("de",$info[1]))[1]);
              $year = trim((explode("|",$info[2]))[0]);
              $hourComp = trim((explode("|",$info[2]))[1]);
              $cHour = trim(explode(" ",$hourComp)[0]);
              $hora = trim(explode(":",$cHour)[0]);
              $minutos = trim(explode(":",$cHour)[1]);
              $mHour = trim(explode(" ",$hourComp)[1]);
              $hour = "$cHour $mHour";
              $pubDate ="$month $day, $year. $hour";
              $monthNumber = [
              'enero' => '1',
              'febrero' => '2',
              'marzo' => '3',
              'abril' => '4',
              'mayo' => '5',
              'junio' => '6',
              'julio' => '7',
              'agosto' => '8',
              'septiembre' => '9',
              'octubre' => '10',
              'noviembre' => '11',
              'diciembre' => '12'
              ];
              $compDate = "$monthNumber[$month]-$day-$year-$hora-$minutos-$mHour";
              $compDate = DateTime::createFromFormat('n-j-Y-g-i-a', $compDate)->getTimestamp();
              $articles[] = [
                "description" => $item->filter('.content_wrapper, .entry-content, p')->text(),
                "title" => $title,
                "link" => $link,
                "pubDate" => $pubDate,
                "compDate" => $compDate,
                "author" => $author
              ];
            //}
          });
        }
        
        usort($articles, function($a, $b){
          return ($b["compDate"]-$a["compDate"]);
        });
  
        setlocale(LC_ALL, 'es_ES.UTF-8');
  
       
        file_put_contents($fullPath, serialize($articles));
      }
  
      return $articles;
      
    }
  /**
   * Get an specific news to display
   *
   * @param String
   *
   * @return array
   */
  private function story($query) {
    // create cache
    $cacheFile = Utils::getTempDir(). md5($query) . date("YmdH") . 'story_cubanet.tmp';
		$notice = false;

		if(file_exists($cacheFile)) $notice = @unserialize(file_get_contents($cacheFile));

		if(!is_array($notice)){
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
    $intro    = $titleObj->count() > 0 ? $titleObj->text() : "";

    // get the images
    $imageObj = $crawler->filter('figure img.size-full');
    $imgUrl   = "";
    $imgAlt   = "";
    $img      = "";
    if ($imageObj->count() != 0) {
      $imgUrl = trim($imageObj->attr("src"));
      $imgAlt = trim($imageObj->attr("alt"));

      // get the image
      if (!empty($imgUrl)) {
        $imgName = Utils::generateRandomHash() . "." . pathinfo($imgUrl, PATHINFO_EXTENSION);
        $img     = \Phalcon\DI\FactoryDefault::getDefault()->get('path')['root'] . "/temp/$imgName";
        file_put_contents($img, file_get_contents($imgUrl));
      }
    }

    // get the array of paragraphs of the body
    $paragraphs = $crawler->filter('div.entry-content p');
    $content    = [];
    foreach ($paragraphs as $p) {
      $content[] = trim($p->textContent);
    }

    // create a json object to send to the template
    $notice = [
      "title"   => $title,
      "intro"   => $intro,
      "img"     => $img,
      "imgAlt"  => $imgAlt,
      "content" => $content,
      "url"     => "https://www.cubanet.org/$query",
    ];
    file_put_contents($cacheFile, serialize($notice));
  }
    return $notice;
  }

  /**
   * Get the link to the news starting from the /content part
   *
   * @param String
   *
   * @return String
   *
   */
  private function urlSplit($url) {
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
   *
   * @return void
   */
  private function respondWithError(Response &$response) {
    error_log("WARNING: ERROR ON SERVICE CUBANET");

    $this->respondText($response, [
      "title"       => "Error en peticion",
      "description" => "Lo siento pero hemos tenido un error inesperado. Enviamos una peticion para corregirlo. Por favor intente nuevamente mas tarde.",
    ]);
  }

  /**
   * Respond text
   *
   * @param \Response $response
   * @param $data
   */
  private function respondText(Response &$response, $data) {
    $response->setLayout('cubanet.ejs');
    $data['description'] = html_entity_decode($data['description']);
    $response->setTemplate("text.ejs", $data);
  }

   /**
   * Change special characters into regulars and make a lowercase string
   *
   * @param $string
   * @param $string
   */
  private function noSpecialCharacter($query) {
    $source = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿŔŕ';
    $modified = 'aaaaaaaceeeeiiiidnoooooouuuuybsaaaaaaaceeeeiiiidnoooooouuuyybyRr';
    $query = utf8_decode($query);
    $query = strtr($query, utf8_decode($source), $modified);
    $query = strtolower($query);
        
    return utf8_encode($query);
  }
}

