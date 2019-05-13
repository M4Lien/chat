<?php
namespace App\Http\Controllers;
use App\Category;
use App\Journal;
use App\Category_Journal;
use App\JournalCategory;
use App\JournalCover;
use App\OpenJournal;
use Illuminate\Http\Request;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use Goutte\Client;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use phpDocumentor\Reflection\File;

class TaylorController extends Controller
{

      public function determinCategory()
    {
        set_time_limit(0);
        $crawler = new Client();
        $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
        $crawler->setClient($guzzleClient);
        $mainUrl = 'https://www.tandfonline.com';
        $mainHtml = $crawler->request('GET', $mainUrl);
        $primaries = [];
        $mainHtml->filter('div[class="container"]>div>ul>li>a')->each(function ($node) use (&$primaries) {
            $primary = (int)filter_var($node->attr('href'), FILTER_SANITIZE_NUMBER_INT);
            array_push($primaries, $primary);
        });

        $template = 'ul[id="ConceptID_allsubjectsFilter"]>li';
//        $tmp = $primaries[0];
//            $primaryUrl = 'https://www.tandfonline.com/topic/'.$tmp.'?target=topic';
//            $primaryHtml = $crawler->request('GET', $primaryUrl);
//        //echo $primaryHtml->filter($template)->first()->html();
//            $primaryHtml->filter($template)->first()->each(function ($node){
//                $this->determin($node, 0, 0);
//            });


        foreach ($primaries as $primary) {
            $primaryUrl = 'https://www.tandfonline.com/topic/' . $primary . '?target=topic';
            $primaryHtml = $crawler->request('GET', $primaryUrl);
            $primaryHtml->filter($template)->first()->each(function ($node) {
                $this->determin($node, 0, 0);
            });
        }
    }

   
    public function determin($node, $parent_id, $level)
    {
        $category = new Category();
        // determin Parent
//        $node->filter('div[class="facet-link-container"]>div>a')->first()->html();
        $node->filter('div[class="facet-link-container"]>div>a')->first()->each(function ($ele) use (&$parent_name, &$parent_id, &$level, &$category, &$parent_sid) {
            $current_name = $ele->text();
            for ($i = 0; $i < $level; $i++) {
                echo "-----------";
            }
            echo $current_name . "-";
            echo $level . "-";
            echo $parent_id . "-";
            preg_match_all('!((?:\d+,?)+)!', $ele->attr('href'), $matches);
            $parent_sid = $matches[0][1];
            echo $parent_sid . "<br>";

            // input this database
            $category->cat_name = $current_name;
            $category->cat_parent_id = $parent_id;
            $category->cat_sid = $parent_sid;
            $category->cat_level = $level;
            $category->save();

        });
        //echo $node->filter('div[class="facet-link-container"]>ul')->children()->html();
        if ($node->filter('div[class="facet-link-container"]>ul')->count() > 0) {
            $node->filter('div[class="facet-link-container"]>ul')->children()->each(function ($chd) use (&$parent_sid, $level) {
                $level = $level + 1;
                $this->determin($chd, $parent_sid, $level);
            });
        }
    }

    
    public function determinJournal()
    {
        $current_category_names = Category::all()->sortbyDesc('cat_level');
        $journalUrls = [];
        foreach ($current_category_names as $current_category_name) {
            $journalUrlTemplate = "https://www.tandfonline.com/topic/" . $current_category_name['cat_sid'] . "?content=title&target=titleSearch&sortBy=TitleSort&pageSize=50&subjectTitle=&startPage=0";
            set_time_limit(0);
            $crawler = new Client();
            $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
            $crawler->setClient($guzzleClient);
            $string = [];
            $journalHtml = $crawler->request('GET', $journalUrlTemplate);
            $journalHtml->filter('ul[class="tab-nav"]>li>a')->each(function ($node) use (&$string) {
                $string = [];
                array_push($string, $node->text());
            });
            if (isset($string[0])) {
                $counts = (int)filter_var($string[0], FILTER_SANITIZE_NUMBER_INT);

                if ($counts % 50 != 0) {
                    $count = $counts / 50;
                    $count = intval($count);
                } else {
                    $count = $counts / 50;
                }
                $category_id = $current_category_name['cat_sid'];
                for ($i = 0; $i <= $count; $i++) {
                    $journalUrlTemplate = "https://www.tandfonline.com/topic/" . $category_id . "?content=title&target=titleSearch&sortBy=TitleSort&pageSize=50&subjectTitle=&startPage=" . $i;
                    $journalHtml = $crawler->request('GET', $journalUrlTemplate);
                    $journalHtml->filter('h4[class="art_title"]>a')->each(function ($node) use (&$journalUrls, &$category_id) {
                        $journalUrl = $node->attr('href');
                        $journalCategory = new JournalCategory();
                        $journalCategory->journal_url = $journalUrl;
                        $journalCategory->category_id = $category_id;
                        $journalCategory->save();
//                    $this->journalContent($journalUrl, $category_id);
                    });
                }
            } else {
                continue;
            }
        }
    }

    
    public function journalContent()
    {
        $journal_categories = JournalCategory::all();
        foreach ($journal_categories as $journal_category) {
            $journal_slugs = array();
            $token = strtok($journal_category['journal_url'], "/");
            while ($token !== false) {
                $token = strtok("/");
                array_push($journal_slugs, $token);
            }
            $journal_slug = $journal_slugs[0];
            $category_id = $journal_category['category_id'];

            $category_journal = new Category_Journal();
            $journal = new Journal();

            if (is_null(Journal:: where('jo_slug', $journal_slug)->first())) {
                set_time_limit(0);
                $crawler = new Client();
                $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
                $crawler->setClient($guzzleClient);
                $getIsn = "https://www.tandfonline.com/action/journalInformation?journalCode=" . $journal_slug;
                $journalInHtml = $journalHtml = $crawler->request('GET', $getIsn);
                $journalTitle = "";
                $journalInHtml->filter('div[class="title-container"]>h1>a')->each(function ($node) use (&$journalTitle) {
                    $journalTitle = $node->text();
                });
                $jo_isn="";
                $ejo_isn="";
                $journalInHtml->filter('div[class="widget-body body body-none  body-compact-all"]')->each(function ($node) use (&$jo_isn,&$ejo_isn) {
                    if($node->filter('span[class="serial-item serialDetailsIssn"]')->count()>0){
                        $jo_isn=$node->filter('span[class="serial-item serialDetailsIssn"]')->text();
                    }
                    if($node->filter('span[class="serial-item serialDetailsEissn"]')->count()>0){
                        $ejo_isn=$node->filter('span[class="serial-item serialDetailsEissn"]')->text();
                    }
                });
                if($jo_isn!==""){
                    $jo_isn = str_replace("Print ISSN: ", "", $jo_isn);
                }
                if($ejo_isn!==""){
                    $ejo_isn = str_replace("Online ISSN: ", "", $ejo_isn);
                }
              

                $journal->jo_title = $journalTitle;
                $journal->jo_slug = $journal_slug;
                $journal->jo_issn = $jo_isn;
                $journal->ejo_issn= $ejo_isn;
                $journal->save();
                $input_id = Journal::all()->count();
                $category_journal->cat_sid = $category_id;
                $category_journal->jo_id = $input_id;
                $category_journal->save();
            } else {
                $jo_id = Journal::where('jo_slug', $journal_slug)->first();
                $category_journal->cat_sid = $category_id;
                $category_journal->jo_id = $jo_id['jo_id'];
                $category_journal->save();
            }
        }
    }


    
    public function readArticle(Request $request)
    {
        $rules = array(
            'issn' => 'required',
            'year' => 'required|numeric|digits:4',
        );
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        else
        {
            $isn=$request->input('issn');
            $year=$request->input('year');
            $searchUrls='https://www.tandfonline.com/action/doSearch?AllField='.$isn.'&content=standard&dateRange=%5B'.$year.'0101+TO+'.$year.'1231%5D&target=default&sortBy=Earliest&pageSize=50&subjectTitle=&startPage=0';
            set_time_limit(0);
            $crawler = new Client();
            $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
            $crawler->setClient($guzzleClient);
            $string = [];
            $articleHtml = $crawler->request('GET', $searchUrls);

            // get article url

            $articleHtml->filter('ul[class="tab-nav"]>li>a')->each(function ($node) use (&$string) {
            $string = [];
            array_push($string, $node->text());
            });
            if (isset($string[0])) {
                $counts = (int)filter_var($string[0], FILTER_SANITIZE_NUMBER_INT);
                if ($counts % 50 != 0) {
                    $count = $counts / 50;
                    $count = intval($count);
                } else {
                    $count = $counts / 50;
                }
                $articleUrls = array();
                for ($i = 0; $i <= $count; $i++) {
                    $searchUrls='https://www.tandfonline.com/action/doSearch?AllField='.$isn.'&content=standard&dateRange=%5B'.$year.'0101+TO+'.$year.'1231%5D&target=default&sortBy=Earliest&pageSize=50&subjectTitle=&startPage='.$i;
                    $ArticleHtml = $crawler->request('GET', $searchUrls);
                    $ArticleHtml->filter('div[class="art_title"]')->each(function ($node) use (&$articleUrls ,&$year,&$isn) {
                        $article=array();
                        if ($node->filter('div[class="article-type"]')->count() > 0 ) {
                            $article['article_type']=$node->filter('div[class="article-type"]')->text();
                            $article['doi_url']=$node->filter('span[class="hlFld-Title"]>a')->attr('href');
                            $article['year']=$year;
                            $article['issn']=$isn;
                            array_push($articleUrls, $article);
                        }
                        else{
                            $article['article_type']="Not exists";
                            $article['doi_url']=$node->filter('span[class="hlFld-Title"]>a')->attr('href');
                            $article['year']=$year;
                            $article['issn']=$isn;
                            array_push($articleUrls, $article);
                        }
                    });
                }
                $file_name = $isn . "_" . $year;
                $file = fopen(storage_path("doi/".$file_name.".txt"), "w");
                $articleUrls = json_encode($articleUrls);
                fwrite($file, $articleUrls);
                echo "success"."----" .$isn."----".$year. "----"."<br>";
            }
            else
            {
                echo "Not exist Articles";
            }
        }
   }



    public function OriginalCopyreadArticle(Request $request)
    {
        $journal_issns = array();
        if($request->has('issn')) {
            $issns = $request->input('issn');
            $token = strtok($issns, ",");
            while ($token !== false) {
                array_push($journal_issns, $token);
                $token = strtok(",");
            }
        }
//        if(count($journal_issns)>0)
        foreach ($journal_issns as $isn) {
            for($year=2016; $year<=2018; $year++ ){
                $searchUrls='https://www.tandfonline.com/action/doSearch?AllField='.$isn.'&content=standard&dateRange=%5B'.$year.'0101+TO+'.$year.'1231%5D&target=default&sortBy=Earliest&pageSize=50&subjectTitle=&startPage=0';
                set_time_limit(0);
                $crawler = new Client();
                $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
                $crawler->setClient($guzzleClient);
                $string = [];
                $articleHtml = $crawler->request('GET', $searchUrls);
                // get article url

                $articleHtml->filter('ul[class="tab-nav"]>li>a')->each(function ($node) use (&$string) {
                    $string = [];
                    array_push($string, $node->text());
                });
                if (isset($string[0])) {
                    $counts = (int)filter_var($string[0], FILTER_SANITIZE_NUMBER_INT);
                    if ($counts % 50 != 0) {
                        $count = $counts / 50;
                        $count = intval($count);
                    } else {
                        $count = $counts / 50;
                    }
                    $articleUrls = array();
                    for ($i = 0; $i <= $count; $i++) {
                        $searchUrls='https://www.tandfonline.com/action/doSearch?AllField='.$isn.'&content=standard&dateRange=%5B'.$year.'0101+TO+'.$year.'1231%5D&target=default&sortBy=Earliest&pageSize=50&subjectTitle=&startPage='.$i;
                        $ArticleHtml = $crawler->request('GET', $searchUrls);
                        $ArticleHtml->filter('div[class="art_title"]')->each(function ($node) use (&$articleUrls ,&$year,&$isn) {
                            $article=array();
                            if ($node->filter('div[class="article-type"]')->count() > 0 ) {
                                $article['article_type']=$node->filter('div[class="article-type"]')->text();
                                $article['doi_url']=$node->filter('span[class="hlFld-Title"]>a')->attr('href');
                                $article['year']=$year;
                                $article['issn']=$isn;
                                array_push($articleUrls, $article);
                            }
                            else{
                                $article['article_type']="Not exists";
                                $article['doi_url']=$node->filter('span[class="hlFld-Title"]>a')->attr('href');
                                $article['year']=$year;
                                $article['issn']=$isn;
                                array_push($articleUrls, $article);
                            }
                        });
                    }
                    $file_name = $isn . "_" . $year;
                    $file = fopen(storage_path("doi/".$file_name.".txt"), "w");
                    $articleUrls = json_encode($articleUrls);
                    fwrite($file, $articleUrls);
                    echo "success"."----" .$isn."----".$year. "----"."<br>";
                }
                else
                {
                    echo "Not exist Articles";
                }
            }
        }
    }

  
    public function articleContent()
{
//        public function articleContent($articleUrl, $isn, $from_year, $to_year){
    $directory=storage_path('doi');
    if (! is_dir($directory)) {
        exit('Invalid diretory path');
    }
    $files = array();
    foreach (scandir($directory, 1) as $file) {
        if ('.' === $file) continue;
        if ('..' === $file) continue;
        $files[] = $file;
    }
    foreach($files as $filename){
               $contents = fopen(storage_path('doi/'.$filename), "r");
        $s = fgets($contents);
        $articleUrls = json_decode($s, true);
        foreach ($articleUrls as $articleUrl) {
            $totalArticleUrl = "https://www.tandfonline.com" . $articleUrl['doi_url'];
            if($articleUrl['article_type']=="Article") {
                set_time_limit(0);
                $crawler = new Client();
                $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
                $crawler->setClient($guzzleClient);
                // article content reading

                $articleContent = $crawler->request('GET', $totalArticleUrl);

                // journal name and journal slug reading

                $journal_name = "";
                $journal_slug = "";
                $articleContent->filter('div[class="title-container"]>h1>a')->each(function ($node) use (&$journal_name, &$journal_slug) {
                    $journal_name = $node->text();
                    $journal_slug = $node->attr('href');
                });
                // article name reading

                $article_name = "";
                $articleContent->filter('span[class="NLM_article-title hlFld-title"]')->each(function ($node) use (&$article_name) {
                    $article_name = $node->text();
                });

                if ($article_name !== "") {
                    $article_name = $article_name;
                } else {
                    $article_name = "";
                }
                // article dot reading

                $article_doi = "";
                $articleContent->filter('li[class="dx-doi"]>a')->each(function ($node) use (&$article_doi) {
                    $article_doi = $node->text();
                });

                if ($article_doi !== "") {
                    $article_doi = $article_doi;
                } else {
                    $article_doi = "";
                }

                // article author reading
                $article_authors = "";
                $articleContent->filter('a[class="entryAuthor"]>span[class="overlay"]')->each(function ($node) use (&$article_authors) {
                    if ($article_authors == "") {
                        $article_authors = $article_authors . str_replace($node->text(), "", $node->parents()->text());
                    } else {
                        $article_authors = $article_authors . ", " . str_replace($node->text(), "", $node->parents()->text());
                    }
                });
                if ($article_authors !== "") {
                    $article_authors = $article_authors;
                } else {
                    if ($articleContent->filter('a[class="entryAuthor overlayed"]')->count()>0){
                        $article_authors=$articleContent->filter('a[class="entryAuthor overlayed"]')->text();
                    }
                    else{
                        $article_authors = "";
                    }
                }


                // article abstract reading

                $article_abstracts = "";
                $articleContent->filter('div[class="abstractSection abstractInFull"]>p')->each(function ($node) use (&$article_abstracts) {
                    if ($article_abstracts == "") {
                        $article_abstracts = $node->html();
                    } else {
                        $article_abstracts = $article_abstracts . "<br>" . $node->html();
                    }
                });

                $article_abstracts=htmlspecialchars($article_abstracts);
                if ($article_abstracts !== "") {
                    $re1='alt=""';
                    $re2='"data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"';
                    $re3='class="no-mml-formula"';
                    $re4='class="mml-formula"';
                    $re5='data-formula-source=';
                    $re6='{"type" : "image", "src" : "';
                    $re7="}'";
                    $re8="'";
                    $a=htmlentities($re1);
                    $b=htmlentities($re2);
                    $c=htmlentities($re3);
                    $d=htmlentities($re4);
                    $e=htmlentities($re5);
                    $f=htmlentities($re6);
                    $g=htmlentities($re7);
                    $h=htmlentities($re8);
                    $aaa=str_replace($a,"",$article_abstracts);
                    $bbb=str_replace($b,"",$aaa);
                    $ccc=str_replace($c,"",$bbb);
                    $ddd=str_replace($d,"",$ccc);
                    $eee=str_replace($e,"",$ddd);
                    $fff=str_replace($f,"",$eee);
                    $ggg=str_replace($g,'"',$fff);
                    $article_abstracts=str_replace($h,'"',$ggg);
                    $article_abstract="";
                    echo $article_abstracts."<br>";
                    $i=1;
                    if($articleContent->filter('div[class="abstractSection abstractInFull"]>p>span>img')->count()>0){
                        $articleContent->filter('div[class="abstractSection abstractInFull"]>p>span>img')->each(function ($child) use (&$article_abstracts, &$article_abstract, &$articleUrl,$totalArticleUrl, &$i){
                            $preImage=$child->attr('data-formula-source');
                            $array = json_decode($preImage, true);
                            if(isset($array['src'])){
                                $url=$array['src'];
                                $imageUrl="https://www.tandfonline.com".$array['src'];
                                $content=file_get_contents($imageUrl, false, stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false))));
                                $filename_full= str_replace("https://www.tandfonline.com/doi/full/10.1080/","",$totalArticleUrl);
                                $filename_doi=str_replace("https://www.tandfonline.com/doi/abs/10.1080/","",$filename_full);
                                $path='images/tags/'.$articleUrl['issn'].'_'.$articleUrl['year'].'_'.str_replace(".","@",$filename_doi).'_'.$i.'.png';
                                $img_path=storage_path($path);
                                file_put_contents($img_path , $content);
                                $article_abstracts=str_replace($url, $path,$article_abstracts);
                                $i++;
                            }
                        });
                        $article_abstract=$article_abstracts;
                    }
                    else{
                        $article_abstract = $article_abstracts;
                    }
                } else {
                    $article_abstract = "";
                }
                // article keyword reading

                $article_keywords = "";
                $articleContent->filter('div[class="abstractKeywords"]>div>a')->each(function ($node) use (&$article_keywords) {
                    if ($article_keywords == "") {
                        $article_keywords = $article_keywords . $node->text();
                    } else {
                        $article_keywords = $article_keywords . ", " . $node->text();
                    }
                });
                if ($article_keywords !== "") {
                    $article_keyword = $article_keywords;
                } else {
                    $article_keyword = "";
                }

                // volume and issue and page number reading

                $volume="";
                $issue="";
                $page="";
                if($articleContent->filter('div[class="title-container"]>h2>a')->count()>0){
                    $preIssue= $articleContent->filter('div[class="title-container"]>h2>a')->text();
                    $issue= (int)filter_var($preIssue, FILTER_SANITIZE_NUMBER_INT);
                    if($issue!==0 and is_int($issue)){
                        $preVolume=$articleContent->filter('div[class="title-container"]>h2')->text();
                        $preIssue= $articleContent->filter('div[class="title-container"]>h2>a')->text();
                        $PreIssue="- ".$preIssue;
                        $realVolume=str_replace($PreIssue, "", $preVolume);
                        $array1 = (explode(",",$realVolume));
                        $volume = (int)filter_var($array1[0], FILTER_SANITIZE_NUMBER_INT);
                        if($articleContent->filter('div[class="widget-body body body-none  body-compact-all"]>span')->count()>0){
                            $prePages=$articleContent->filter('div[class="widget-body body body-none  body-compact-all"]>span')->text();
                            $page=str_replace("Pages ", "", $prePages);
                            $page=trim($page);
                        }
                    }
                    else{
                        $volume="";
                        $issue="";
                        $page="";
                    }
                }
                $journal_slugs = array();
                $token = strtok($journal_slug, "/");
                while ($token !== false) {
                    $token = strtok("/");
                    array_push($journal_slugs, $token);
                }
                $slug = $journal_slugs[0];
                if ($article_abstracts !== "") {
                    $journal_name = trim($journal_name);
                    $article_name = trim($article_name);
                    $filename_full= str_replace("https://www.tandfonline.com/doi/full/10.1080/","",$totalArticleUrl);
                    $filename_doi=str_replace("https://www.tandfonline.com/doi/abs/10.1080/","",$filename_full);
                    $filename=$articleUrl['issn'].'_'.$articleUrl['year'].'_'.str_replace(".","@",$filename_doi);
//                    $filename = $articleUrl['issn'] . "_" . $articleUrl['year']. "_" . rand(10,1000000000000000);

                    // ""처리부분
                    $abstract_img_url="";
                    $journal_name='"'.$journal_name.'"';
                    $journal_slug='"'.$journal_slug.'"';
                    $article_name='"'.$article_name.'"';
                    $year='"'.$articleUrl['year'].'"';
                    $issn='"'.$articleUrl['issn'].'"';
                    $article_authors='"'.$article_authors.'"';
                    $article_doi='"'.$article_doi.'"';
                    $volume='"'.$volume.'"';
                    $issue='"'.$issue.'"';
                    $page='"'.$page.'"';
                    $totalArticleUrl='"'.$totalArticleUrl.'"';
                    $article_keyword='"'.$article_keyword.'"';
                    $article_abstract='"'.$article_abstract.'"';
                    $abstract_img_url='"'.$abstract_img_url.'"';
                    // end
                    $result = "@" . "article" . "{{$slug},
        journal={$journal_name},
        slug={$journal_slug},
        title={$article_name},
        year={$year},
        issn={$issn},
        author={$article_authors},
        doi={$article_doi},
        volume={$volume},
        number={$issue},
        pages={$page},
        url={$totalArticleUrl},
        keywords={$article_keyword},
        abstract={$article_abstract},
        abstract_img_url={$abstract_img_url},
        }";
                    $file = fopen(storage_path("bib/".$filename . ".bib"), "w");
                    fwrite($file, $result);
                    fclose($file);
                }
                else{
                    $article_abstract="";
                    if($articleContent->filter('div[class="firstPage"]>a>img')->count()>0){
                        $preImage= $articleContent->filter('div[class="firstPage"]>a>img')->attr('data-src');
                        $array = json_decode($preImage, true);
                        $imageUrl="https://www.tandfonline.com".$array['src'];
                        $content=file_get_contents($imageUrl, false, stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false))));
                        $filename_full= str_replace("https://www.tandfonline.com/doi/full/10.1080/","",$totalArticleUrl);
                        $filename_doi=str_replace("https://www.tandfonline.com/doi/abs/10.1080/","",$filename_full);
                        $path='images/'.$articleUrl['issn'].'_'.$articleUrl['year'].'_'.str_replace(".","@",$filename_doi).'.png';
//                        $path='images/'.rand(10,100000000000).'.png';
                        $img_path=storage_path($path);
                        file_put_contents($img_path , $content);
                        $journal_name = trim($journal_name);
                        $article_name = trim($article_name);
                        $filename=$articleUrl['issn'].'_'.$articleUrl['year'].'_'.str_replace(".","@",$filename_doi);
//                        $filename = $articleUrl['issn'] . "_" . $articleUrl['year']. "_" . rand(10,1000000000000000);

                        // ""처리부분
                        $journal_name='"'.$journal_name.'"';
                        $journal_slug='"'.$journal_slug.'"';
                        $article_name='"'.$article_name.'"';
                        $year='"'.$articleUrl['year'].'"';
                        $issn='"'.$articleUrl['issn'].'"';
                        $article_authors='"'.$article_authors.'"';
                        $article_doi='"'.$article_doi.'"';
                        $volume='"'.$volume.'"';
                        $issue='"'.$issue.'"';
                        $page='"'.$page.'"';
                        $totalArticleUrl='"'.$totalArticleUrl.'"';
                        $article_keyword='"'.$article_keyword.'"';
                        $article_abstract='"'.$article_abstract.'"';
                        $abstract_img_url='"'.$path.'"';
                        // end
                        $result = "@" . "article" . "{{$slug},
        journal={$journal_name},
        slug={$journal_slug},
        title={$article_name},
        year={$year},
        issn={$issn},
        author={$article_authors},
        doi={$article_doi},
        volume={$volume},
        number={$issue},
        pages={$page},
        url={$totalArticleUrl},
        keywords={$article_keyword},
        abstract={$article_abstract},
        abstract_img_url={$abstract_img_url},
        }";
                        $file = fopen(storage_path("bib/".$filename . ".bib"), "w");
                        fwrite($file, $result);
                        fclose($file);
                        echo $article_doi.""."<br>";
                    }

                }
            }
        }
    }
}

    
    public function test()
    {
        set_time_limit(0);
        $crawler = new Client();
        $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
        $crawler->setClient($guzzleClient);
        $mainUrl = 'https://www.tandfonline.com';
        $mainHtml = $crawler->request('GET', $mainUrl);
        $primaries = [];
        $mainHtml->filter('div[class="container"]>div>ul>li>a')->each(function ($node) use (&$primaries) {
            $primary = (int)filter_var($node->attr('href'), FILTER_SANITIZE_NUMBER_INT);
            array_push($primaries, $primary);
        });
        echo $primaries->count();
    }


    public function autoLoad(){
       $directory=storage_path('doi');
        if (! is_dir($directory)) {
            exit('Invalid diretory path');
        }
        $files = array();
        foreach (scandir($directory, 1) as $file) {
            if ('.' === $file) continue;
            if ('..' === $file) continue;
            $files[] = $file;
        }
        foreach($files as $file){
            $contents = fopen(storage_path('doi/'.$file), "r");
            $s = fgets($contents);
            $articleUrls = json_decode($s, true);
            echo $file."";
        }
    }

    public function imageReading(){
        $totalArticleUrl = "https://www.tandfonline.com/doi/full/10.1080/14787318.2017.1386881";
        set_time_limit(0);
        $crawler = new Client();
        $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
        $crawler->setClient($guzzleClient);
        $imageContent = $crawler->request('GET', $totalArticleUrl);
        $preImage= $imageContent->filter('div[class="firstPage"]>a>img')->attr('data-src');
        $array = json_decode($preImage, true);
        $imageUrl="https://www.tandfonline.com".$array['src'];
        $content=file_get_contents($imageUrl, false, stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false))));
        $img_path=storage_path('images/'.rand(10,100000000000).'.png');
        file_put_contents($img_path , $content);
    }


        public function issn(Request $request){
        if($request->has('issn')){
            $issns=$request->input('issn');
            $journal_issns = array();
            $token = strtok($issns, ",");
            while ($token !== false) {
                $token = strtok(",");
                array_push($journal_issns, $token);
            }
            foreach ($journal_issns as $issn) {
                echo $issn;
            }
        }
    }


    public function latex(){
        /// bib downloading
        //------------------------------------------------------------------------------------
//        $totalArticleUrl = "https://www.tandfonline.com/doi/full/10.1080/08927022.2018.1547823";
//        set_time_limit(0);
//        $crawler = new Client();
//        $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
//        $crawler->setClient($guzzleClient);
//        $articleContent = $crawler->request('GET', $totalArticleUrl);
//        $downloadCitation=$articleContent->filter('li[class="downloadCitations"]>a')->attr('href');
//        $downloadUrl="https://www.tandfonline.com".$downloadCitation;
//        $downloadBib=$crawler->request('GET', $downloadUrl);
//        $form=$downloadBib->filter('form[name="frmCitmgr"]')->form();
//        $param=array('format'=>'bibtex', 'include'=>'abs');
//        $crawler->submit($form, $param);
//        $result=$crawler->getResponse()->getContent();
//        $filename = rand(10,1000000000000000);
//        $file = fopen(storage_path("bib/".$filename . ".bib"), "w");
//        fwrite($file, $result);
//        fclose($file);
//        echo "Success";
        //---------------------------------------------------------------------


        // image downloading in abstract
        $totalArticleUrl = "https://www.tandfonline.com/doi/full/10.1080/00036811.2018.1489962";
        set_time_limit(0);
        $crawler = new Client();
        $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
        $crawler->setClient($guzzleClient);
        $articleContent = $crawler->request('GET', $totalArticleUrl);
        $article_abstracts = "";
        $articleContent->filter('div[class="abstractSection abstractInFull"]>p')->each(function ($node) use (&$article_abstracts) {
            if ($article_abstracts == "") {
                $article_abstracts = $node->html();
            } else {
                $article_abstracts = $article_abstracts . "<br>" . $node->html();
            }
        });
        $article_abstracts=htmlspecialchars($article_abstracts);
        if ($article_abstracts !== "") {
            $re1='alt=""';
            $re2='"data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"';
            $re3='class="no-mml-formula"';
            $re4='data-formula-source=';
            $re5='{"type" : "image", "src" : "';
            $re6="}'";
            $re7="'";
            $a=htmlentities($re1);
            $b=htmlentities($re2);
            $c=htmlentities($re3);
            $d=htmlentities($re4);
            $e=htmlentities($re5);
            $f=htmlentities($re6);
            $g=htmlentities($re7);
            $aaa=str_replace($a,"",$article_abstracts);
            $bbb=str_replace($b,"",$aaa);
            $ccc=str_replace($c,"",$bbb);
            $ddd=str_replace($d,"",$ccc);
            $eee=str_replace($e,"",$ddd);
            $fff=str_replace($f,"",$eee);
            $article_abstracts=str_replace($g,'"',$fff);
            $article_abstract="";
            $i=1;
            if($articleContent->filter('div[class="abstractSection abstractInFull"]>p>span>img')->count()>0){
                $articleContent->filter('div[class="abstractSection abstractInFull"]>p>span>img')->each(function ($child) use (&$article_abstracts, &$article_abstract,&$totalArticleUrl,&$i){
                    $preImage=$child->attr('data-formula-source');
                    $array = json_decode($preImage, true);
                    $url=$array['src'];
                    $imageUrl="https://www.tandfonline.com".$array['src'];
                    $content=file_get_contents($imageUrl, false, stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false))));
//                    $path='images/tags/'.rand(10,100000000000).'.png';
                    $filename_doi= str_replace("https://www.tandfonline.com/doi/full/10.1080/","",$totalArticleUrl);
                    $path='images/tags/'.str_replace(".","@",$filename_doi).'_'.$i.'.png';
                    $img_path=storage_path($path);
                    file_put_contents($img_path , $content);
                    $article_abstracts=str_replace($url, $path,$article_abstracts);
                    $i++;
                });
            }
            else{
                $article_abstracts = $article_abstracts;
            }
        } else {
            $article_abstracts = "";
        }
    }

    public function Cover(){
        $journals=Journal::all();
        $i=1;
        foreach($journals as $journal){
            $totalJournalUrl = "https://www.tandfonline.com/toc/".$journal['jo_slug']."/current";
//            $totalJournalUrl = "https://www.tandfonline.com/toc/bbrm20/current";
            if (is_null(JournalCover:: where('jo_issn', $journal['jo_issn'])->first())) {
                set_time_limit(0);
                $crawler = new Client();
                $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
                $crawler->setClient($guzzleClient);
                $journalContent = $crawler->request('GET', $totalJournalUrl);
                if($journalContent->filter('div[class="publicationCoverImage"]>img')->count()>0){
                    $journalCoverUrl = $journalContent->filter('div[class="publicationCoverImage"]>img')->attr('src');
                    $imageUrl = "https://www.tandfonline.com" . $journalCoverUrl;
                    $content = file_get_contents($imageUrl, false, stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false))));
                    $path = 'images/cover/' . $journal['jo_issn'] . '.jpg';
                    $img_path = storage_path($path);
                    file_put_contents($img_path, $content);
                    $journalCover = new JournalCover();
                    $journalCover->jo_issn = $journal['jo_issn'];
                    $journalCover->jo_cover_url = $path;
                    $journalCover->save();
                }
            }
            else{
                echo $i."-----------".$journal['jo_issn']."<br>";
                $i++;
            }
        }
    }

    public function openAccessJournalList(){
        $openAccessJournalListUrl="https://www.tandfonline.com/openaccess/openjournals";
        set_time_limit(0);
        $crawler = new Client();
        $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
        $crawler->setClient($guzzleClient);
        $articleContent = $crawler->request('GET', $openAccessJournalListUrl);
        $openJournalSlug=array();
//        $articleContent->filter('div[class="teaser"]>div>div[class="text"]>h3>a')->each(function ($node) use (&$openJournalSlug) {
//            $openJournalTotalSlug=$node->attr('href');
//            $journal_title=$node->text();
//            $token = strtok($openJournalTotalSlug, "/");
//            while ($token !== false) {
//                $token = strtok("/");
//                array_push($openJournalSlug, $token);
//            }
//            if(!$openJournalSlug[0]=="" && $openJournalSlug[0]!=="tsnm20"){
//                $journal_slug = $openJournalSlug[0].'20';
//                $openJournalIsn="https://www.tandfonline.com/action/journalInformation?journalCode=".$journal_slug;
//                $openJournalSlug=array();
////                echo $journal_slug."<br>";
//            }
//            elseif($openJournalSlug[0]=="tsnm20"){
//                $journal_slug=$openJournalSlug[0];
//                $openJournalIsn="https://www.tandfonline.com/action/journalInformation?journalCode=".$journal_slug;
//                $openJournalSlug=array();
////                echo $journal_slug;
//            }
//            else{
//                $journal_slug=strtok($openJournalTotalSlug,"/").'20';
//                $openJournalIsn="https://www.tandfonline.com/action/journalInformation?journalCode=".$journal_slug;
//                $openJournalSlug=array();
////                echo $journal_slug."<br>";
//            }
//            if(is_null(OpenJournal:: where('journal_slug', $journal_slug)->first())){
//                set_time_limit(0);
//                $crawler = new Client();
//                $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
//                $crawler->setClient($guzzleClient);
//                $journalContent=$articleContent = $crawler->request('GET', $openJournalIsn);
//                $isn = [];
//                $journalContent->filter('div[class="widget-body body body-none  body-compact-all"]>span')->each(function ($node) use (&$isn) {
//                    array_push($isn, $node->text());
//                });
//                $jo_isn = $isn[0];
//                $jo_isn = str_replace("Print ISSN: ", "", $jo_isn);
//                $jo_isn = str_replace("Online ISSN: ", "", $jo_isn);
//                $openJournal=new OpenJournal();
//                $openJournal->journal_slug=$journal_slug;
//                $openJournal->journal_issn=$jo_isn;
//                $openJournal->journal_title=$journal_title;
//                $openJournal->action='1';
//                $openJournal->save();
//            }
//            else{
//                echo $journal_slug."<br>";
//            }
//
//        });
        $isns=OpenJournal::all();
        $this->OpenFullArticle($isns);
    }

    public function OpenFullArticle($journal_issns)
    {
        foreach ($journal_issns as $issn) {
            $isn=$issn['journal_issn'];
            for($year=2016; $year<=2018; $year++ ){
                $searchUrls='https://www.tandfonline.com/action/doSearch?AllField='.$isn.'&content=standard&dateRange=%5B'.$year.'0101+TO+'.$year.'1231%5D&target=default&sortBy=Earliest&pageSize=50&subjectTitle=&startPage=0';
                set_time_limit(0);
                $crawler = new Client();
                $guzzleClient = new GuzzleClient(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
                $crawler->setClient($guzzleClient);
                $string = [];
                $articleHtml = $crawler->request('GET', $searchUrls);
                // get article url
                $articleHtml->filter('ul[class="tab-nav"]>li>a')->each(function ($node) use (&$string) {
                    $string = [];
                    array_push($string, $node->text());
                });

                if (isset($string[0])) {
                    $counts = (int)filter_var($string[0], FILTER_SANITIZE_NUMBER_INT);
                    if ($counts % 50 != 0) {
                        $count = $counts / 50;
                        $count = intval($count);
                    } else {
                        $count = $counts / 50;
                    }
                    $articleUrls = array();
                    for ($i = 0; $i <= $count; $i++) {
                        $searchUrls='https://www.tandfonline.com/action/doSearch?AllField='.$isn.'&content=standard&dateRange=%5B'.$year.'0101+TO+'.$year.'1231%5D&target=default&sortBy=Earliest&pageSize=50&subjectTitle=&startPage='.$i;
                        $ArticleHtml = $crawler->request('GET', $searchUrls);
                        $ArticleHtml->filter('div[class="art_title"]')->each(function ($node) use (&$articleUrls,&$year,&$isn) {
                            $article=array();
                            if ($node->filter('div[class="article-type"]')->count() > 0 ) {
                                $article['article_type']=$node->filter('div[class="article-type"]')->text();
                                $article['doi_url']=$node->filter('span[class="hlFld-Title"]>a')->attr('href');
                                $article['year']=$year;
                                $article['issn']=$isn;
                                array_push($articleUrls, $article);
                            }
                            else{
                                $article['article_type']="Not exists";
                                $article['doi_url']=$node->filter('span[class="hlFld-Title"]>a')->attr('href');
                                $article['year']=$year;
                                $article['issn']=$isn;
                                array_push($articleUrls, $article);
                            }
                        });
                    }
                    $file_name = $isn . "_" . $year;
                    $file = fopen(storage_path("openDoi/".$file_name.".txt"), "w");
                    $articleUrls = json_encode($articleUrls);
                    fwrite($file, $articleUrls);
                    echo "success"."----" .$isn."----".$year. "----"."<br>";
                }
                else
                {
                    echo "Not exist Articles";
                }
            }
        }
    }

    
}
?>
