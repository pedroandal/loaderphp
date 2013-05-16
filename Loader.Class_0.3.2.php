<?php                                         
    ini_get("date.timezone") || ini_set("date.timezone", "Europe/London");          
    ini_set("display_errors", "on");
    //error_reporting(E_ERROR); //production
    error_reporting(E_ALL); //development
    ini_set('log_error', "loader.log");                                            
    ini_set("error_prepend_string", "\n<fieldset class='loaderphp_error' style='border:thin solid red;'><legend style='color:red;'>ERROR</legend>");
    ini_set("error_append_string", "\n</fieldset>");

    class Loader{                            

        private $name="Loader Framework";
        private $version="0.3.2";

        private $currentView = 0;           
        private $params;                                      
        private $document_path;
        private $charset = 'UTF-8';
        private $method;   
        private $mainView; 
        private $use_cache=true; //save to file 
        private $debug=false; //show comments 
        private $minimizeHTML=false; //minimize HTML
        private $debugMsg="";
        private $body_content="";
        private $head_content="";
        private $script_body="";

        /**
        * export variables to view
        * @internal
        * @var array
        */
        private $vars=array();

        /**
        * views 
        * 
        * @var mixed(string/array)
        */
        private $views=array(); 
        //private $viewsContent=array();

        /**
        * html headers
        * 
        * @var array[]
        */
        private $headers = array(
            'script' => array(), 
            'link'   => array(),  
            'meta'   => array()
        );

        public $cacheDir = "LoaderFramework_cache"; 
        private $icon="/favicon.ico";   
        private $title;         
        // private $var;  

        public function setDebug($debug=true) { $this->debug = $debug; }   
        public function useCache($bool=true) { $this->use_cache = $bool; } 
        public function compactHTML($bool=true) { $this->minimizeHTML = $bool; } 
        public function setTitle($title) { $this->title = $title; }
        public function setIcon($icon) { $this->icon = $icon; }    
        public function setEncoding($encode) { $this->charset = $encode; }    

        public function __isset($name) { return isset($this->vars[$name]); } 
        public function __unset($name) { unset($this->vars[$name]); }                
        public function __set($name, $value="") {   
            $noVars = array('files', 'cookie', 'session', 'get', 'post');             
            if(is_string($name) && strlen($name)>0 && substr($name, 0, 2)!="__" && !in_array($name, $noVars)){
                $this->vars[$this->cleanVar($name)] = $value; 
                return true;
            }
            return false;
        }  
        public function __get($name) { //echo "$name: {$this->vars[$name]}<br/>"; 
            if(is_string($name) && strlen($name)>0){
                return !is_null($this->vars) && array_key_exists($name, $this->vars) ? $this->vars[$name] : ""; 
            }  
            return "";
        }

        private function cleanVar($nameVarOrArrayVars)
        {
            $pattern = '/[^a-zA-Z0-9_]/';
            if(is_array($nameVarOrArrayVars)){
                $tmpArray = array();
                foreach($nameVarOrArrayVars as $key=>$val)
                    $tmpArray[preg_replace($pattern, '_', $key)] = $val; 
                return $tmpArray;
            } else if(is_scalar($nameVarOrArrayVars))
                return preg_replace($pattern, '_', $nameVarOrArrayVars);   
            else 
                return "";
        }
        public function __construct()
        {                                                                  
            $method = strtolower($_SERVER["REQUEST_METHOD"]); //metodo HTTP usado
            in_array($method, array("get", "post"))  || $this->error(400, "You trying use a not supported request method.");
            $this->method = $fn = function_exists($method) ? $method : "all";   //função que é chamada por defeito 'all()'                                    
            function_exists($fn) && is_callable($fn) || $this->error(405, "The application can't process the request."); //erro, caso nao exista função          

            $get = $this->cleanVar($_GET);
            $post = $this->cleanVar($_POST);
            
            if($method == 'get') $this->vars = array_merge($post, $get);
            else if($method == 'post') $this->vars = array_merge($get, $post);  

            $this->vars['get'] = $get;
            $this->vars['post'] = $post;
            $this->vars['files'] = $this->cleanVar($_FILES);
            $this->vars['cookie'] = $this->cleanVar($_COOKIE);
            if(isset($_SESSION)) 
                $this->vars['session'] = $this->cleanVar($_SESSION);
                
            $this->vars['method'] = $method;
            $this->vars['document_path'] = $this->document_path = dirname($_SERVER['SCRIPT_FILENAME']); 
        }

        /**
        * Define ficheiro com layout base da página HTML
        * 
        * @param string Caminho relativo ou absoluto para vista principal
        */                             
        public function setMainView($view) {
            if(!isset($this->mainView)){ 
                if(!is_string($view)) $this->error(500, 'MasterView must be a string.\n$loader->setMasterView(string $pathname);');
                if(!(file_exists(realpath($view)) || file_exists(realpath($view = "$view.tpl")))) 
                    $this->error(404, "MasterView file doesn't exist.\nPlease referer a valid file.");
                $this->mainView = $view; 
            } else if(is_string($view) && file_exists(realpath($view))) 
                $this->mainView = $view; 
        }                   

        /**
        * Adiciona ficheiros com partes da página HTML
        * 
        * @param mixed(string/array) Caminho para vista ou conjunto de vistas
        */
        public function addViewParts($views, $name='') 
        {   
            switch(gettype($views)){
                case 'array':
                    if(count($this->views)==0) $this->views = $views;
                    else $this->views = array_merge($this->views, $views); 
                    break;
                case 'string':
                    if($name=="") $this->views[] = $views;
                    else $this->views[$name] = $views;
                    break;
                case 'NULL': $this->views = array(); //desativa uso de templates
                default:
                    if($this->debug) $this->warning('Wrong template name');
                    break;
            }    
        }

	private function warning($warn)
	{
		$this->debugMsg = "{$this->debugMsg}\n$warn\n";
	}

        public function loadResources($filepath, $section=null)
        {
            if(empty($filepath) && $this->debug)
                $this->warning('Empty resources file path');
            else {
                $resPath = realpath("{$this->document_path}/$filepath");

                if(is_file($resPath)){       
                    $resources = parse_ini_file($resPath, $section);   
                    
                    if(!$resources) throw new Exception("Error evaluating '" + $filepath + "'!");       

                    if(is_string($section) && is_array($resources) && array_key_exists($section, $resources))
                        $resources =  $resources[$section];                             
                    return is_array($resources) && count($resources)>0 ? array_map(function($i){
                            return htmlentities($i); 
                        }, $resources) : false;  

                } else if($this->debug)
                    $this->warning('Wrong resources path or invalid file');
            }
            return false;
        }

        /**
        * Open connection with DB
        * 
        * @param string File with connection settings
        */
        public function dbConnect($path=NULL)
        {
            $db = new Database;
            if(is_file($path)){
                $conn = parse_ini_file($path); 
                if(count($conn)>0 && array_key_exists("server", $conn)){
                    $db->connectFromFile($conn);
                } else
                    $this->error(500, "Attribute required: server=?");
            }
            return $db;
        }

        /**        
        *  generate and execute the page                                                    
        * @method 
        */
        public function processPage()
        {            
            $debug = $this->processRequest();      //processa o modelo; obtem as variaveis
            if($this->debug) {
                $this->debug($debug); //faz debug ao modelo
            }
            if(count($this->views)==0) 
            {                
                $tpl = pathinfo($_SERVER["SCRIPT_FILENAME"]);    
                $tpl = "{$tpl['dirname']}/{$tpl['filename']}.tpl";   
                if(is_file($tpl)) $this->views[] = $tpl; //adiciona o template por defeito e continua para processa-lo
                unset($tpl);
            }

            if($this->use_cache) 
            { //if is used a chache file with generated content
                $cacheFile = $this->getCacheFilename();
                if(is_file($cacheFile)) { //check if cache file exists and its a file
                    $tplModificationTime = filemtime($cacheFile);

                    //if($this->debug) $this->debug("View already created in FS", "PHP Loader");
                    //return $this->writeResponse();  //output the response

                    //if(false) return $this->writeResponse();
                }
                unset($cacheFile);
            }

            $this->body_content = $this->loadViewPart($this->mainView); // Load mainView             
            while(empty($this->body_content)) { //retrieve views until last one
                count($this->views) > 0 || $this->error(404, "There are no views to show.\nIt's impossible return any response! Add at least one view");
                $this->body_content = $this->loadViewPart(array_shift($this->views));
            }
            $this->manageViews(); //load viewParts  
            $this->body_content = preg_replace( //clean html view
                array('/<!DOCTYPE[^>]*>/i', "/<\/?html[^\>]*>/i", "/<\/?head[^\>]*>/i", "/<\/?body[^\>]*>/i", "/<!--.*-->/", "/\/\*.*\*\//"), 
                "", 
                $this->body_content);      
            //$this->body_content = preg_replace(array('/\r\n\s+/', '/\s+\r\n/'), "\r\n", $this->body_content);

            $this->processFor();
            $this->processIfElse();           

            $this->processVars($this->body_content); 
            $this->body_content = preg_replace("/<\/php:(if|for)\s*>/i", "<?php } ?>", $this->body_content);
            //$this->body_content = preg_replace("/(\n|\r|\t)/", "", $this->body_content);   

            $this->processHeaders();  
            if($this->use_cache) $this->cacheFile();
            $this->source($this->body_content);  
            $this->writeResponse();
        }   

        private function processHeaders()
        {        
            $meta=array();            
            $meta['Content-Type'] = 'text/html; charset=utf-8'; //text encoding
            $meta['X-UA-Compatible'] = 'IE=edge,chrome=1';      //IE9 compatibility
            $meta['generator'] = $this->name; // PHP Framework 
            $meta["Revisit-After"] = "30 Days";
            $meta["robots"] = "all,index";
            $this->headers['meta'] = $meta;

            if(isset($this->title)) {                                                                       
                while($this->cleanText("<title>", "</title>")); //apaga todos titulos                             
                $this->head_content = "{$this->head_content}\n<title>{$this->title}</title>"; //adiciona titulo
            } else {
                while(($head=$this->getAndCleanText("<title>", "</title>"))!==false){
                    $this->head_content = "{$this->head_content}$head"; 
                }
            }                 
            
            $metaCount = preg_match_all('/<link([^-\>]*)>/i', $this->body_content, $link, PREG_OFFSET_CAPTURE);
            while(($head=$this->getAndCleanText("<link ", ">"))!==false){
                if(!(isset($this->icon) && preg_match("/\s+rel=[\"']shortcut icon[\"']/i", $head)))
                    $this->head_content = "{$this->head_content}$head"; 
            }                    
            $metaCount = preg_match_all('/<meta([^>]*)>/i', $this->body_content, $meta, PREG_OFFSET_CAPTURE);
            
            while(($head=$this->getAndCleanText("<meta ", ">"))!==false){                         
                $this->head_content = "{$this->head_content}$head"; 
            }                                                          
            while(($head=$this->getAndCleanText("<style ", "</style>"))!==false){
                //$head = preg_replace("/\s{2,}/", " ", $head);    
                $this->head_content = "{$this->head_content}$head";  
            }                                                          
            while(($head=$this->getAndCleanText("<script ", "</script>"))!==false){               
                if(preg_match("/\s+src=['\"]([^'\"]+)['\"]\s*/", $head, $script)) 
                {                                               
                    if(!preg_match("/^(ht|f)tps?:\/\//", $script[1]) && $script[1][0] != '/')    
                        $script[1] = str_replace(array($_SERVER['DOCUMENT_ROOT'], '\\'), array('', '/'), $script[1]);

                    if(!in_array($script[1], $this->headers['script']))
                        $this->headers['script'][] = $script[1];                             
                } else { 
                    //$head = preg_replace(array("/\/\*.+?\*\/|\/\/.*(?=[\n\r])/"/*, "/[\r\n]/"*/), "", $head);   
                    $head = preg_replace(array("/\/\/[^\n]+\n/", "/\s+\r\n/"), "\r\n", $head);   

                    $this->script_body = "{$this->script_body}$head"; 
                }          
            }                                                         
            if(isset($this->icon)) {      
                $pathIcon = $this->icon[0]=='/' ? $_SERVER["DOCUMENT_ROOT"] : dirname($_SERVER["SCRIPT_FILENAME"]);
                $pathIcon = "$pathIcon/{$this->icon}";  
                if(is_file($pathIcon))                                                                                                
                    $this->head_content = "{$this->head_content}\n<link rel='shortcut icon' href='{$this->icon}'/>"; 
            }                                                                                                                           
        } 

        private function manageViews()
        {
            while(preg_match("/<php:view(\s+([^>]+))?>/i", $this->body_content, $view))
            {  
                $view[1] = trim($view[1], "\/ ");
                if(empty($view[1]))
                    $view[1] = $this->currentView++; 

                $htmlPart = "";
                if(array_key_exists($view[1], $this->views))
                    $htmlPart = $this->loadViewPart($this->views[$view[1]]);

                $this->body_content = str_replace($view[0], $htmlPart, $this->body_content);
            }    
            unset($view);                                                                                     
        }

        private function loadViewPart($tpl)
        {  
            $html="";
            if(is_string($tpl) && (is_file($tpl) || is_file($tpl = "$tpl.tpl"))) //se existir template
                $html = file_get_contents($tpl); //le conteudo do template 
            return $html;    
        }

        private function compactHead($cssText)
        {   
            /*
            $media = "";                                       
            if(preg_match("/media=['\"](\w+[^'\"])/i", strstr($cssTetxt, '>', true), $t)) $media = " media='{$t[1]}'";

            $cssTetxt = preg_replace(array("/<style.*[^>]/i", "/<\/style>/i", "/\r/", "/\n/"), "", $cssTetxt); //retira tag's    
            $cssTetxt = preg_replace("/\s+/", " ", $cssTetxt); //retira tag's  
            // return "<style type='text/css'$media>$cssTetxt</style>";                                                             */  

            return  preg_replace(array("/\s{2,}/", "/\r?\n/"), array(" ", ""),$cssText);
        }                                                                                                                         

        private function generateMetaHeaders(){
            $metaName = array(
                "http-equiv"    => array("content-type", "x-ua-compatible"),
                "name"          => array("generator", "robots", "revisit-after")
            );
            
            $metaHtml='';
            foreach($this->headers['meta'] as $headerName => $headerValue){
                foreach($metaName as $metaIndex => $metaType)
                    if(in_array(strtolower($headerName), $metaType))
                        $metaHtml = "$metaHtml<meta $metaIndex='$headerName' value='$headerValue'/>";
            }
            return $metaHtml;
        }

        private function writeResponse($file="")
        {
            if($this->debug) echo $this->debugMsg;
            ob_start();
            include empty($file) ? $this->getCacheFilename() : $file;  //output the php script
            $content = ob_get_flush(); 

            if(in_array('HTTP_ACCEPT_ENCODING', $_SERVER))
            {                                           
                $enc = explode(",", $_SERVER['HTTP_ACCEPT_ENCODING']);
                switch($enc[0])
                {
                    case "gzip": 
                        header("Content-Encoding: gzip");
                        echo gzencode($content); 
                        break;

                    case "deflate":
                        header("Content-Encoding: deflate");
                        echo gzdeflate($content);
                        break;
                    default:
                        include empty($file) ? $this->getCacheFilename() : $file;    //output the php script
                }
            }    
        }

        /***
        * Procura texto entre 2 strings, devolve o texto encontrado e apaga-o
        * 
        * @param string 
        * @param string 
        * @return string
        */
        private function getAndCleanText($initStr, $endStr, $cleanTags=false) {  
            if(is_string($initStr) && is_string($endStr) && strlen($initStr)>0 && strlen($endStr)>0 && ($posI = stripos($this->body_content, $initStr))!== false) {
                $posE = stripos($this->body_content, $endStr, $posI);
                if($endStr == ">")
                    while(in_array($this->body_content[$posE-1], array("-", "?"))){
                        $posE = stripos($this->body_content, $endStr, $posE+1);
                }
                if($posE > $posI) {
                    $retStr = substr($this->body_content, $posI, $posE-$posI+strlen($endStr));
                    $this->body_content = substr_replace($this->body_content, "", $posI, strlen($retStr)); 
                    return $retStr;
                }
            }                     
            return false;
        } 

        /***
        * Apaga texto
        *      
        * @param mixed 
        * @param mixed 
        */
        private function cleanText($initStr, $endStr) 
        {   
            if(is_string($initStr) && is_string($endStr) && strlen($initStr)>0 && strlen($endStr)>0) {
                $initStr =  stripos($this->body_content, $initStr);
                $endStr = strlen($endStr) + stripos($this->body_content, $endStr, $initStr);
            }

            if(is_int($initStr) && is_int($endStr) && $endStr > $initStr)  {                                                              
                $this->body_content = substr_replace($this->body_content, "", $initStr, $endStr - $initStr); 
                return true;
            }
            return false;    
        }

        /***
        * Devolve texto procurado entre 2 strings
        * 
        * @param mixed 
        * @param mixed 
        * @return string
        */
        private function getText($initStr, $endStr) 
        { 
            if(is_string($initStr) && is_string($endStr) && strlen($initStr)>0 && strlen($endStr)>0 && ($initStr = stripos($this->body_content, $initStr))!== false)
                $endStr = strlen($endStr) - $initStr + stripos($this->body_content, $endStr, $initStr);
            
            if(is_int($initStr) && is_int($endStr) && $endStr > $initStr)  
                return substr($this->body_content, $initStr, $endStr);

            return false;
        }  

        private function processFor()
        {
            while(preg_match("/<php:for\s+([^>]+)>/i", $this->body_content, $for))
            {
                if($for[1]!="" && preg_match('/^(.*)\s+(is|as|in)\s+(.*)$/', $for[1], $tmpVar))
                {
                    if($tmpVar[2]=='in')
                        $forVars = array(ltrim($tmpVar[3],'$'), $tmpVar[1]);
                    else
                        $forVars = array(ltrim($tmpVar[1],'$'), $tmpVar[3]);
                        
                    $forOldVar=explode(",", $forVars[1]); 

                    if(preg_match("/\[([^\.\.]+)\.\.([^\]]+)\]/i", $forVars[0], $range))
                    {
                        list($max, $step) = explode(",", $range[2]);
                        $min = str_replace("\$??", "\$__", $range[1]);
                        $max = str_replace("\$??", "\$__", $max);
                        $step = str_replace("\$??", "\$__", trim($step));
                        $forVars[0] = trim($max, " \$\?"); 
/*
                        $replace = sprintf(
                            "%1\$s=\"$step\"; 
                            if(empty(%1\$s) ? true : is_numeric(%1\$s)) 
                            foreach(range($min, empty(%1\$s) ? $max : abs($max-$min)<%1\$s ? $min : $max %2\$s) as", 
                            "\$__{$forVars[0]}_rangeFn",  empty($step) ? "" : ", $step"); 
*/
                        $replace = sprintf(
                            '%1$s = intval(%2$s); if(empty(%1$s) ? true : is_numeric(%1$s)) foreach(range(%3$s, empty(%1$s) ? %4$s : abs(%4$s - %3$s)<%1$s ? %3$s : %4$s %5$s) as', 
                            "\$__{$forVars[0]}_rangeFn", $step, $min, $max, empty($step) ? "" : ", $step"); 
                        unset($range, $max, $step);
                    } else 
                        $replace = sprintf(
                            'if(array_key_exists("%1$s", $this->vars) && is_array($this->vars["%1$s"])&& count($this->vars["%1$s"])>0) foreach($this->vars["%1$s"] as', 
                            $forVars[0]);

                    $forVarsItems = count($forOldVar); 
                    for($forIti = 0; $forIti < $forVarsItems; $forIti++)
                        $forOldVar[$forIti] = trim($forOldVar[$forIti], ' $');

                    if($forVarsItems>1)
                        $replace="$replace \$__{$forVars[0]}_{$forOldVar[0]} =>";

                    $replace="$replace \$__{$forVars[0]}_{$forOldVar[$forVarsItems==1 ? 0 : 1]}) {";

                    if($forVarsItems>2)
                        $replace="\$__{$forVars[0]}_{$forOldVar[2]}=-1; $replace \$__{$forVars[0]}_{$forOldVar[2]}++;";

                    $forOffsetI=$fiPos=strpos($this->body_content, $for[0])+strlen($for[0]);
                    while(true)
                    {
                        $fePos=strpos($this->body_content, "</php:for>", $fiPos);
                        $forHtml=substr($this->body_content, $fiPos, $fePos-$fiPos);
                        if(($tmpPos=strpos($forHtml, "<php:for "))===false) break;
                        $fiPos=$fePos+10; // 10=strlen("</php:for>")
                    }
                    $forHtml=substr($this->body_content, $forOffsetI, $fePos-$forOffsetI); 

                    $forNewVar = array();
                    for($forIti = 0; $forIti < $forVarsItems; $forIti++){
                        $forNewVar[$forIti] = "\$??{$forVars[0]}_{$forOldVar[$forIti]}\$1";
                        $forOldVar[$forIti] = "/\\\${$forOldVar[$forIti]}([^a-zA-Z0-9_])/";
                    }  
                    
                    $forNewHtml = preg_replace($forOldVar, $forNewVar, $forHtml);
                    $this->body_content=str_replace("{$for[0]}$forHtml</php:for>", "<?php $replace ?>$forNewHtml<?php } ?>", $this->body_content);
                }
            }   
        }

        private function processIfElse()
        {                                                                                                                                                                            
            while(preg_match("/<php:(if|else)\s*([^>]*)>/i", $this->body_content, $ifelse))
            {  
                $ifelse[2] = trim($ifelse[2], ' /');
                if(!empty($ifelse[2])) {
                    $vars = $this->extractVars($ifelse[2]);

                    if(count($vars)>0) {
                        foreach($vars as $i=>$var) {
                            $varClass = $this->processVar($var);
                            $ifelse[2] = substr_replace($ifelse[2], $varClass, $i, strlen($var)+1);
                            $vars[$i] = $varClass;
                        }
                    }
                    $ifelse[2] = preg_replace(
                        array("/\s+eq\s+/", "/\s+neq\s+/", "/\s+lt\s+/", "/\s+lte\s+/", "/\s+gt\s+/", "/\s+gte\s+/", "/\s+and\s+/", "/\s+or\s+/", "/\s+not\s+/", "/\s+mod\s+/"), 
                        array(" == ", " != ", " < ", " <= ", " > ", " >= ", " && ", " || ", " !", " % "), 
                        $ifelse[2]);
                        
                    $ifelse[2] = sprintf('if(isset(%s) && (%s))', implode(', ', $vars), $ifelse[2]);
                }
                if($ifelse[1]=="else")
                    $ifelse[2] = "} else {$ifelse[2]}";
                    
                $this->body_content = str_replace($ifelse[0], "<?php $ifelse[2]{ ?>", $this->body_content);                        
            }
        }
        
        private function extractVars($text)
        {
            $arrVars = array();
            $count = preg_match_all('/\$([a-zA-Z0-9_\.\?]+)/', $text, $vars, PREG_OFFSET_CAPTURE);
            if($count > 0) {
                for($i=$count-1; $i>-1; $i--) 
                    $arrVars[$vars[0][$i][1]] = $vars[1][$i][0];
            }
            return $arrVars;
        }

        private function processVars(&$html) 
        { /* Replacing ordinary variables */
        //"/([^\\\$])(\\\$([a-z0-9_\.\?]+))(|[a-z_]+(:[a-z0-9_\"'])?)?(}?)/i"
            $count = preg_match_all('/([^\$])(\$([a-z0-9_\.\?]+))(}?)/i',$html, $var, PREG_OFFSET_CAPTURE);
            
            if($count > 0){
                for($i=$count-1; $i>=0; $i--) {
                    if($var[3][$i][0]=="" || $var[3][$i][0][0]=="." || $var[3][$i][0]=="this" || substr($var[3][$i][0], 0, 2)=="__") 
                        continue;
                    
                    $extra=0;
                    if($var[1][$i][0]=="{" && $var[4][$i][0]=="}") $extra=1;

                    $replace = $this->processVar($var[3][$i][0], true);
                    $replace = "<?php $replace; ?>";
                    $html = substr_replace($html, $replace, $var[2][$i][1]-$extra, strlen($var[2][$i][0])+$extra+$extra);
                }
            }
        }
        
        private function processVar($varName, $outputVar = false)
        {
            if(substr($varName, 0, 2)!="??")
                $replace = sprintf('%s %s', $outputVar ? 'if(array_key_exists("%1$s", $this->vars) && is_scalar($this->vars["%1$s"]%2$s)) echo' : '', '$this->vars["%1$s"]%2$s');
            else{
                $replace = sprintf('%s %s', $outputVar ? 'if(is_scalar($__%1$s%2$s)) echo' : '', ' $__%1$s%2$s');
                $varName = substr($varName, 2); 
            }

            $params = explode(".", $varName);
            $vall = count($params);
            $arrVars = "";
            if($vall > 1) {
                for($vindex=1; $vindex<$vall; $vindex++) {
                    if(is_numeric($params[$vindex]))                     
                        $arrVars = "{$arrVars}[{$params[$vindex]}]";     
                    else
                        $arrVars = "{$arrVars}[\"{$params[$vindex]}\"]"; 
                }
                $replace = str_replace('this->vars)', 'this->vars) && isset($this->vars["%1$s"]%2$s)', $replace);
            }
            return sprintf($replace, $params[0], $arrVars);
        }

        /**
        * Guarda ficheiro de cache
        * @return boolean
        */
        private function cacheFile() 
        {                       
            $cacheFilename = $this->getCacheFilename(); 
            $folderCache = dirname($cacheFilename);                                         
            if(is_dir($folderCache) || mkdir($folderCache, 0777, true))
            {                    
                $script="";
                foreach($this->headers['script'] as $s) 
                    $script = "$script<script type='text/javascript' src='$s'></script>";                                                                                                                                                      
                $content = sprintf("<!DOCTYPE html>\n<html>\n\t<head>\n\t%s%s</head>\n<body>\n\t%s%s%s%s</body>\n</html>\r\n",    
                    $this->head_content, 
                    $this->generateMetaHeaders(),
                    $this->debugMsg, 
                    $this->body_content,
                    $script,
                    $this->script_body
                );      
                if($this->minimizeHTML) $content = preg_replace("/>\s+</", "><", $content); //retirar espaços entre tags  
                return strlen($content) == file_put_contents($cacheFilename, $content, LOCK_EX); //guarda vista gerada e verifica se escreveu bem o ficheiro 
            }
            return false;                                                                                                                
        }
        
        /**
        * Devolve caminho completo para ficheiro de cache
        * @return string
        */
        private function getCacheFilename() 
        {
            $folderCache = "{$_SERVER['DOCUMENT_ROOT']}/{$this->cacheDir}"; //pasta onde guarda os ficheiros de cache
            $cacheFilename = trim(base64_encode($_SERVER["SCRIPT_FILENAME"]), "="); //gera nome unico para cada ficheiro   
            return "$folderCache/$cacheFilename.php";                                                                                                                 
        }

        public function params($name){
            return isset($name) && array_key_exists($name, $this->params) ? $this->params[$name] : false; 
        }
        
        /**
        * Processa o modelo de dados, onde as variaveis são criadas
        * @return string
        * @returns coments
        */
        private function processRequest()
        {   
            ob_start();   
            call_user_func($this->method, $this); //chama a função do modelo para processar as variaveis
            $content = ob_get_contents(); //obtem os comentarios
            ob_end_clean();
            return $content;
        } 

        private function error($code, $msg)
        {
            $errors = array(  
                '400' => "Bad Request",
                '404' => "Not Found",
                '405' => "Method Not Allowed",
                '500' => "Internal Server Error", 
            );
            header("HTTP/1.1 $code {$errors[$code]}");
            die("<!DOCTYPE html><html><head><meta http-equiv='Content-Type' content='text/html; charset={$this->charset}' /><title>$code {$errors[$code]}</title></head>
                <body><center><h4>$msg</h4>\n<hr/>{$this->name}/{$this->version}</center></body></html>");   
        }     
        private function debug($var, $title="")
        {   
            if(!empty($var))
                $this->debugMsg = sprintf("%s\n<fieldset style='border:thin solid blue; margin-top:2px;'>%s<div style='width:100%%; margin-right:2px; max-height: 250px; overflow:auto;'>%s</div></fieldset>",
                    $this->debugMsg, empty($title) ? "" : "<legend style='color:blue'>$title</legend>", is_array($var) ? var_export($var, true) : $var);
        }     
        private function source($var, $title="")
        {   
            if(!empty($var))
                $this->debugMsg = sprintf("%s\n<fieldset style='border:thin solid blue; margin-top:2px;'>%s<textarea style='width:100%%; margin-right:2px; max-height: 250px;' rows='15'>%s</textarea></fieldset>",
                    $this->debugMsg, empty($title) ? "" : "<legend>$title</legend>", htmlentities(is_array($var) ? var_export($var, true) : preg_replace("/\n\s+\r?\n/", "", $var)));                                                                                                       
        }                                           
    }                                

    function eq($var1, $var2) { return $var1==$var2; }
    function neq($var1, $var2) { return $var1!=$var2; }
    function lt($var1, $var2) { return $var1<$var2; }
    function lte($var1, $var2) { return $var1<=$var2; }
    function gt($var1, $var2) { return $var1>$var2; }    
    function gte($var1, $var2) { return $var1>=$var2; } 
    function between($var, $v1, $v2) 
    {       
        if($v1>$v2)                         
            return $v2<=$var && $var<=$v1;
        else
            return $v1<=$var && $var<=$v2;
    }     
    function startsWith($substr, $str, $case_sensitive = FALSE){
        if(is_bool($case_sensitive) && !$case_sensitive){
            $substr = strtolower($substr);
            $str = strtolower($str); 
        }
        return substr($str, 0, strlen($substr)) == $substr;
    }

    define("ASSOC", "ASSOC");
    define("NUM", "NUM");
    define("BOTH", "BOTH");

    class Database{
        private $driver;
        private $conn;
        private $errorLog;
        private $result;

        function getConnectionInfo(){
            switch($this->driver){
                case 'mysqli':
                    return mysqli_get_connection_stats($this->conn);
            }
        }

        /**
        * Open connection with MySQL Database Server
        * 
        * @param string Hostname or IP Address
        * @param strin DB Username
        * @param string DB password
        * @param string DB Name
        * @param int Server listen port
        */
        public function MySQL($host="127.0.0.1", $database, $username, $password, $port=3306){ 
            $this->getDriver("mysql");
            switch($this->driver){
                case "mysqli":
                    if(extension_loaded("mysqli")){
                        if($this->conn = mysqli_init()){
                            ini_set("mysqli.allow_persistent", 1);
                            mysqli_options($this->conn, MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 1');
                            mysqli_options($this->conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
                            mysqli_real_connect($this->conn, "p:$host", $username, $password, $database, $port);
                        }
                        if($this->conn){ //is really connected
                            $this->conn->set_charset("utf8");
                            mysqli_select_db($this->conn, $database) || $this->ErrorLog("MySQL error: database '$database' don't exist.");
                        } else                                                                                                      
                            $this->connectError(mysqli_connect_error(), mysqli_connect_errno());
                    } else
                        $this->ErrorLog("Extension not loaded: mysqli !\n Trying next extension!");
                case "mysql":
                    if(extension_loaded("mysql")){
                        $this->driver = "mysql";
                        if(($this->conn = mysql_pconnect("$host:$port", $username, $password)) != FALSE) { 
                            mysql_set_charset("utf-8", $this->conn);
                            mysql_select_db($database, $this->conn) || $this->ErrorLog("MySQL error: database '$database' don't exist.");
                            break;
                        } else 
                            $this->connectError(mysql_error(), mysql_errno());    
                    }
                default:
                    $this->errorDie("ERROR: Can't connect to database!<br>{$this->errorLog}");
            }
        }

        /**
        * Open SQlite embebed connection 
        * 
        * @param string Path to the SQLite database
        * @param string encryption key
        */
        public function SQlite($filename, $key=NULL){
            $this->getDriver("sqlite");
            switch($this->driver){
                case "sqlite3":
                    if(extension_loaded("sqlite3") && is_file($filename)){
                        $this->conn = new SQLite3($filename, SQLITE3_OPEN_READWRITE, $key);  
                        $errorCode = $this->conn->lastErrorCode(); 
                        if($errorCode == 0) break; // No error 
                        $this->connectError($this->conn->lastErrorMsg(), $errorCode);
                    }
                case "sqlite":
                    if(extension_loaded("sqlite") && is_file($filename)){
                        if(($this->conn = sqlite_popen($filename, 0666, $error)) == FALSE)
                            $this->connectError($error);
                    }
                default:
                    die("ERROR: Can't connect to database!<br>{$this->errorLog}");
            }
        }

        /**
        * Open connection with Firebird Database Server
        * 
        * @param string Hostname or IP Address
        * @param string DB alias or DB filepath
        * @param strin DB Username
        * @param string DB password
        */
        public function Firebird($host="127.0.0.1", $database, $username, $password){
            $this->InterBase_Firebird($host, $database, $username, $password);
        }

        /**
        * Open connection with InterBase Database Server
        * 
        * @param string Hostname or IP Address
        * @param string DB alias or DB filepath
        * @param strin DB Username
        * @param string DB password
        */
        public function InterBase($host, $database, $username, $password){
            $this->InterBase_Firebird($host, $database, $username, $password);
        }                    
        public function PostgreSQL(){
            $this->getDriver("postgresql");
            $this->errorDie("Not Implemented!");
        }
        public function Oracle($host){
            $this->getDriver("oracle");
            $this->errorDie("Not Implemented!");

            switch($this->driver){
                case 'oci8_11g':

                case 'oci8':
            }
        }
        public function MS_SQL_Server(){
            $this->getDriver("ms_sql_server");
            $this->errorDie("Not Implemented!");
        }
        public function MongoDB(){
            $this->getDriver("mongodb");
            $this->errorDie("Not Implemented!");
        }
        
        private function replaceParams(&$sql, $params)
        {
            switch(gettype($params))
            {
                case "string":   
                    $sql = str_replace(":1", $this->escapeString($params), $sql); 
                    break;
                case "array": 
                    if(count($params)>0)          
                        for($i=count($params); $i>0; $i--)           
                        $sql = str_replace(":$i", $this->escapeString($params[$i-1]), $sql);  
            }
            return $sql;
        }
        
        function execute($sql, $params=NULL)
        {
            if(!is_string($sql) || empty($sql))
                return $this->result = NULL;

            if(startsWith("select", $sql))
                return $this->query($sql, $params);
            
            if(!is_null($params))
                $this->replaceParams($sql, $params);
                
            switch($this->driver){
                case 'sqlite' :
                    if(sqlite_exec($this->conn, $sql, $error))
                        return sqlite_changes($this->conn);
                       else{
                           $this->errorLog($error);
                           return false;
                       }   
                    break;
                case 'sqlite3' :
                    return $this->conn->exec($sql);   
                    break;
                case 'interbase':
                    return ibase_query($this->conn, $sql);
                    break;
                case 'mysqli':
                    return mysqli_real_query($this->conn, $sql) ? mysqli_field_count($this->conn) : false; 
                    break;
                case 'mysql':
                    return mysql_query($sql, $this->conn) ? mysql_affected_rows($this->conn) : false;
            }
        }

        /**
        * Execute query against a database
        * 
        * @param string
        * @param mixed
        * @return resource
        */
        function query($sql, $params=NULL)
        {
            if(!is_string($sql) || empty($sql))
                return $this->result = NULL;

            if(!startsWith("select", $sql))
                return $this->execute($sql, $params);
            
            if(!is_null($params))
                $this->replaceParams($sql, $params);

            echo "<span style='display:block; width:100%'>-------------------------", $sql, "-------------------------------------</span>";
            $this->result = false;
            switch($this->driver){
                case 'sqlite' :
                    $this->result = sqlite_unbuffered_query($this->conn, $sql, SQLITE_BOTH, $error_msg);  
                    if(!$this->result)
                        $this->errorLog($error_msg);
                    break;
                case 'sqlite3' :
                    $this->result = $this->conn->query($sql); 
                    break;
                case 'interbase':
                    $this->result = ibase_query($this->conn, $sql);
                    break;
                case 'mysqli':
                    if(mysqli_real_query($this->conn, $sql)) 
                        $this->result = mysqli_use_result($this->conn);
                    break;
                case 'mysql':
                    $this->result = mysql_query($sql, $this->conn);
            }
            //$this->result || $this->queryError();
            return $this->result;
        }

        /**
        * Escape param, removing forbiden chars from html forms , protecting against sql injection attacks
        * 
        * @param string
        */
        function escapeString($param='')
        {
            switch($this->driver)
            {
                case 'mysqli':
                    return mysqli_real_escape_string($param);
                case 'mysql':
                    return mysql_real_escape_string($param);
                case 'sqlite3': 
                    return $this->conn->escapeString($param);
                case 'sqlite':
                    return sqlite_escape_string($param);
                case 'interbase':
                    return addslashes(trim($param, "'"));    
            }
        }

        /**
        * Fetch one row from query result as array
        * 
        * @param string ASSOC|NUM
        */
        function fetch($result_type=ASSOC)
        {
            $result = array();
            if($this->result == false) return $result;

            switch($this->driver)
            {
                case 'mysqli':
                    //if(mysqli_field_count($this->conn))
                    return mysqli_fetch_array($this->result, constant("MYSQLI_$result_type"));
                case 'mysql':
                    return mysql_fetch_array($this->result, constant("MYSQL_$result_type"));
                case 'sqlite3': 
                    return $this->result->fetchArray(constant("SQLITE3_$result_type"));
                case 'sqlite':
                    return sqlite_fetch_array($this->result, constant("SQLITE_$result_type"));
                case 'interbase':
                    $row = ibase_fetch_array($this->result, constant("IBASE_$result_type"));
                    return is_array($row) ? array_change_key_case($row) : $row;
            }
        }

        /**
        * Fetch all or limited number of rows from results
        * 
        * @param int Number of rows to fetch
        */
        function fetchAll($maxResults=0, $result_type=ASSOC)
        {
            $results = array();
            if($this->result == false) return $results;

            $it=0;
            switch($this->driver)
            {
                case 'mysqli':
                    while(($row = mysqli_fetch_array($this->result, constant("MYSQLI_$result_type"))) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = $row;
                    break;
                case 'mysql':
                    while(($row = mysql_fetch_array($this->result, constant("MYSQL_$result_type"))) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = $row;
                    break;
                case 'sqlite3': 
                    while(($row = $this->result->fetchArray(constant("SQLITE3_$result_type"))) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = $row;
                    break;
                case 'sqlite':
                    while(($row = sqlite_fetch_array($this->result, constant("SQLITE_$result_type"))) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = $row;
                    break;
                case 'interbase':
                    while(($row = ibase_fetch_array($this->result, constant("IBASE_$result_type"))) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = array_change_key_case($row);
                    break;
            }
            return $results; 
        }

        private function InterBase_Firebird($host, $db, $usr, $pwd){
            $this->getDriver("interbase");
            if($this->driver == "interbase")
            {
                ini_set("ibase.allow_persistent", 1);
                $this->conn = ibase_pconnect("$host:$db", $usr, $pwd);
            } else 
                $this->loadExtensionError("interbase");
        }

        private function getDriver($engine, $version=NULL){
            $db = $this->supportedDBs(strtolower($engine));
            switch(gettype($db)){
                case "string":
                    $this->driver = $this->loadExtension($db) ? $db : null;
                    break;

                case "array":
                    foreach($db as $e){
                        if($this->loadExtension($e)){
                            $this->driver = $e;
                            return;
                        }
                    }
                    $this->driver = null;
                    break;
            }
        }

        function connectFromFile($data)
        {
            if(!is_null($this->supportedDBs($data['server'])))
            {
                switch($data['server'])
                {
                    case 'mysql':
                    case 'mysqli':
                        $params = array($data['host'], $data['database'], $data['username'], $data['password'], $data['port']);
                        break;
                    case 'firebird':
                    case 'interbase':
                        $params = array($data['host'], $data['database'], $data['username'], $data['password']);
                        break;
                    case 'sqlite3':
                    case 'sqlite':
                        $params = array($data['filename'], $data['key']);
                }
                call_user_func_array(array($this, $data['server']), $params);
            }
        }

        private function queryError(){
            $msg = "Query to BD failed!";

            switch($this->driver){
                case 'mysqli': 
                    $msg = sprintf("%s\n%d: %s", $msg, mysqli_errno($this->conn), mysqli_error($this->conn));
                    break;

                default: 
                    $msg = "$msg\n No driver to connect DB and execute querys.";
            }
            $this->errorLog($msg);
        }

        private function errorLog($log){
            $this->errorLog = "{$this->errorLog}\n$log";
        }

        private function errorDie($msg=''){
            die(empty($msg) ? $this->errorLog : $msg);
        }

        private function loadExtensionError($drvName=null) {
            if(isset($drvName))   
                $this->errorLog = "{$this->errorLog}\nError loading driver: $drvName";
        }
        private function connectError($msg, $code=''){
            $server = array(
                "mysql" => "MySQL",
                "sqlite" => "SQlite",
                "interbase" => "InterBase",
                "firebird" => "Firebird",
                "oracle" => "Oracle"
            );
            $this->errorLog(sprintf('%s connect error%s: %s', $server[$this->driver], empty($code) ? '' : " #$code", $msg));
        }

        function __destruct (){
            unset($this->result, $this->conn);
        }

        public static function supportedDBs($name=''){
            $dbs = array(
                "mysql" => array("mysqli", "mysql"),
                "sqlite" => array("v2"=>"sqlite", "v3"=>"sqlite3" ),
                "interbase" => "interbase",
                "firebird" => "interbase",
                //"postgresql" => "pgsql",
                "oracle" => array("10g"=>"oci8", "11g"=>"oci8_11g"),
                //"ms_sql_server" => "mssql",
                "mongodb" => "mongo"
            );
            return empty($name) ? $dbs : $dbs[$name];        
        }

        private static function loadExtension($extName){
            if(!extension_loaded($extName) && strtolower(ini_get("enable_dl")) == "on"){
                dl(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? "php_$extName.dll" : "$extName.so");
            }
            return extension_loaded($extName);
        }
    }       

    define("IBASE_BOTH", 0x1);
    define("IBASE_ASSOC", 0x2);
    define("IBASE_NUM", 0x3);
    /**
    * Fetch the results and return an array
    * 
    * @param resource
    * @param int
    * @return array
    */
    function ibase_fetch_array($result, $result_type=IBASE_BOTH){ 
        if($result)
        switch($result_type){
            case IBASE_BOTH:  
                if(($rowArr = ibase_fetch_assoc($result)) != false)
                    $rowArr = array_merge(array_values($rowArr), $rowArr);  
            case IBASE_ASSOC:
                $rowArr = ibase_fetch_assoc($result);
                break;
            case IBASE_NUM: 
                $rowArr = ibase_fetch_row($result);
        }
        return $rowArr;
    }

?>

