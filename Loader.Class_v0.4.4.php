<?php
    ini_get("date.timezone") || ini_set("date.timezone", "Europe/London"); 
    //error_reporting(E_ERROR); //production
    error_reporting(E_ALL); //development
    
    ini_set('display_errors', "1");
    ini_set('display_startup_errors', true);
    ini_set('log_errors', true);
                                          
    ini_set('error_log', "loader.log");
    ini_set("error_prepend_string", "\n<fieldset class='loaderphp_error' style='border:thin solid red;'><legend style='color:red;'>ERROR</legend>");
    ini_set("error_append_string", "\n</fieldset>");

    function __autoload($class_name) {  
        if(is_file("models/$class_name.php"))
            include "models/$class_name.php";
    }
 
    $funct = create_function("", "ob_start(); if(function_exists('unload')) call_user_func('unload'); ob_end_clean();");
    register_shutdown_function($funct);
        
    final class Loader{      
        private $name="LoaderPHP Framework";
        private $version="0.4.4";

        private $currentView = 0;
        private $encoding = 'UTF-8';
        private $mainView, $method, $document_root, $document_path; 
        private $cache=true; //save to file
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
            'meta'   => array(),
            'style' => ""
        );

        public $cacheDir = "LoaderFramework_cache"; 
        private $icon="/favicon.ico";   
        private $title;         
        // private $var;  
        
        public function set(){ 
            $args = func_get_args(); 
            
            switch(gettype($args[0])) {
                case 'array' :
                    if(func_num_args() == 1) 
                        foreach($args[0] as $k=>$v)                        
                            $this->_set($k, $v);
                break;
                case 'scalar' :   
                    if(func_num_args() == 2)                             
                        $this->_set($args[0], $args[1]);
                     break; 
            }  
        }
        private function _set($var, $value){ 
            switch(strtolower($var)) {
                case 'mainview' :
                    $this->setMainView($value);
                    break;  
                default:
                    if(gettype($value) == gettype($this->$var)) 
                        $this->$var = $value; 
            }
        }

        /**
        * enable/disable debug at top page
        * 
        * @deprecated
        * @param boolean
        */
        public function setDebug($debug=true) { $this->debug = $debug; } 

        /**   
        * @deprecated
        * enable/disable store of generated view 
        *    
        * @deprecated
        * @param boolean
        */
        public function useCache($bool=true) { $this->cache = $bool; }

        /**
        * disable store of generated view 
        * @property-write
        */
        public function disableCache() { $this->cache = false; }

        public function compressResponse($bool=true) { $this->minimizeHTML = $bool; }
        public function setTitle($title) { $this->title = $title; }
        public function setIcon($icon) { $this->icon = $icon; }
        public function setEncoding($encode) { $this->encoding = $encode; }

        public function __isset($name) { return isset($this->vars[$name]); }
        public function __unset($name) { unset($this->vars[$name]); }
        public function __set($name, $value="") {            
            //$noVars = array('files', 'cookie', 'session', 'get', 'post');
            if(is_string($name) && strlen($name)>0 && substr($name, 0, 2)!="__"/* && !in_array($name, $noVars)*/){
                $this->vars[$this->cleanVar($name)] = is_array($value) ? $this->cleanVar($value) : $value;
                return true;
            }
            error_log("Invalid var name: '$name'");
            return false;
        }
        public function __get($name) { //echo "$name: {$this->vars[$name]}<br/>";
            if(is_string($name) && strlen($name)>0){
                return array_key_exists($name, $this->vars) ? $this->vars[$name] : "";
            }  
            return "";
        }

        private function cleanVar($nameVarOrArrayVars)
        {                                 
            //$pattern = '/[^a-zA-Z0-9_]/';
            $pattern = '/^\W$/';
            if(is_array($nameVarOrArrayVars)){
                $tmpArray = array();
                foreach($nameVarOrArrayVars as $key=>$val){
                    if(is_array($val))
                        $val = $this->cleanVar($val);
                    $tmpArray[preg_replace($pattern, '_', $key)] = $val; 
                }
                return $tmpArray;
            } else if(is_scalar($nameVarOrArrayVars))
                return preg_replace($pattern, '_', $nameVarOrArrayVars);   
            else {
                error_log("Var '$nameVarOrArrayVars' is not integer or string!");
                return "";
            }
        }

        public function __construct() {
            $this->method = $method = strtolower($_SERVER["REQUEST_METHOD"]); //metodo HTTP usado
            in_array($method, array("get", "post"))  || $this->error(403, "You trying use a unsupported request method.");
            $method = function_exists($method) ? $method : "request";   //função que é chamada por defeito 'request()'
            $fct = create_function('$a', 'if(!function_exists($a)) {new Exception("The method \'all\' is deprecated!"); return \'all\';} return $a;'); 
            if("request" == $method) $method = $fct($method);
            function_exists($method) && is_callable($method) || $this->error(405, "The application can't process the request."); //erro, caso nao exista função
                                               
            $get = $this->cleanVar($_GET);               
            $post = $this->cleanVar($_POST);                                  
                                                                                
            if($this->method == 'get') $this->vars = array_merge($post, $get);                                              
            else if($this->method == 'post') $this->vars = array_merge($get, $post);  
                                                                                  
            $this->vars['get'] = $get;
            $this->vars['post'] = $post;
            $this->vars['files'] = $this->cleanVar($_FILES);
            $this->vars['cookie'] = $this->cleanVar($_COOKIE);
            if(isset($_SESSION)) 
                $this->vars['session'] = $this->cleanVar($_SESSION);      		

            $this->vars['method'] = $this->method;
            $this->method = $method;
            $this->vars['script_path'] = $this->script_path = rtrim(str_replace('\\', '/', dirname($_SERVER["SCRIPT_FILENAME"])), '/'); 
            $this->vars['document_root'] = $this->document_root = str_replace('\\', '/', $_SERVER["DOCUMENT_ROOT"]);
        }

        /**
        * Define ficheiro com layout base da página HTML
        * 
        * @param string Caminho relativo ou absoluto para vista principal
        */
        public function setMainView($view) {
            if(!isset($this->mainView)){ 
                if(!is_string($view)) $this->error(500, 'MainView must be a string.\n$loader->setMainView(string $pathname);');
                if(!(file_exists(realpath($view)) || file_exists(realpath($view = "$view.tpl")))) 
                    $this->error(404, "MainView file doesn't exist.\nPlease referer a valid file.");
                $this->mainView = $view; 
            } else if(is_string($view) && file_exists(realpath($view))) 
                $this->mainView = $view; 
        }                   

        /**
        * Adiciona ficheiros com partes da página HTML
        * 
        * @param mixed(string/array) Caminho para vista ou conjunto de vistas
        */
        public function addViewParts($views, $name='') {   
            switch(gettype($views)){
                case 'array':
                    if(count($this->views)==0) $this->views = $views;
                    else $this->views = array_merge($this->views, $views); 
                    break;
                case 'string':
                    if(!is_string($name) || empty($name)) $this->views[] = $views;
                    else $this->views[$name] = $views;
                    break;
                case 'NULL': $this->views = array(); //desativa uso de templates
                    break;
                default:
                    if($this->debug) $this->warning('Wrong template name');
                    break;
            }    
        }

        private function warning($warn) {
            $this->debugMsg = "\n{$this->debugMsg}\n$warn";
        }

        public function loadResources($filepath, $section=null) {
            if(!empty($filepath)) {
                $resPath = realpath($filepath);

                if(is_file($resPath)){       
                    $resources = parse_ini_file($resPath, $section);

                    if(!$resources) throw new Exception("Error evaluating '$filepath'!");

                    if(is_string($section) && is_array($resources) && array_key_exists($section, $resources))
                        $resources =  $resources[$section];                             
                    if(is_array($resources) && count($resources)>0 && count(array_filter($resources, 'is_array')) == 0)
                        $resources = array_map("htmlentities", $resources);
                    return $resources;
                } else
                    $this->warning('Wrong resources path or invalid file');
            } else
                $this->warning('Empty resources file path');
            return false;
        }

        /**
        * Open connection with DB
        * 
        * @param string File with connection settings
        */
        public function dbConnect($path=NULL) {
            $db = new Database;
            if(is_file($path)){
                $data = parse_ini_file($path); 
                if($data!=false && count($data)>0 && array_key_exists("server", $data))
                {
                    if(!is_null($db->supportedDBs($data['server'])))
                    {
                        if(array_key_exists('persistent', $data)) 
                        $db->usePersistentConnection($data['persistent']);

                        switch($data['server'])
                        {
                            case 'mysql':
                            case 'mysqli':
                                $params = array(empty($data['host']) ? '127.0.0.1' : $data['host'], $data['database'], $data['username'], $data['password'], empty($data['port']) ? 3306 : $data['port']);
                                break;
                            case 'firebird':
                            case 'interbase':
                                $params = array(empty($data['host']) ? '127.0.0.1' : $data['host'], $data['database'], $data['username'], $data['password']);
                                break;
                            case 'sqlite3':
                            case 'sqlite':
                                $params = array($data['filename'], $data['key']);
                        }
                        call_user_func_array(array($db, $data['server']), $params);
                    }
                }
                else
                    $this->error(500, "Attribute required: server=?");
            }
            return $db;
        }

        private function internalLoadResources($file) {
            $fileLines = array();
            $filepath = '';      
                                                                                                                 
             if(is_file($file))
                $fileLines = file($file, FILE_USE_INCLUDE_PATH | FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);   
                
             if(is_file("resources/$file"))
                $fileLines = file("resources/$file", FILE_USE_INCLUDE_PATH | FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);   
            restore_include_path();
             
            $arr = array();   
            if(is_array($fileLines) and count($fileLines) > 0) {                          
                foreach($fileLines as $line) {
                    $item = explode("=", $line, 2);
                    if(count($item) != 2) continue;
                    $arr[trim($item[0])] = trim($item[1]);
                } 
            }
            return $arr;   
        }
        
        private function generatePage()        
        {   
            //if is used a chache file with generated content
            $cacheFile = $this->getCacheFilename();
            if(is_file($cacheFile)) { //check if cache file exists and its a file
                $tplModificationTime = filemtime($cacheFile);

                if(is_file($this->mainView) && filemtime($this->mainView) > $tplModificationTime)
                    return true;

                if(count($this->views) > 0) { 
                    foreach($this->views as $view) {
                        if(is_file($view) && filemtime($view) > $tplModificationTime) {                            
                            return true;                                               
                        }
                    }
                }
                if($this->debug) $this->debug("View already created in FS", "PHP Loader");
                return false;
            } else
                return true; //nao existe vista gerada           
        }

        /**
        *  generate and execute the page
        * @method 
        */
        public function processPage()
        {                                    
            if(count($this->views) == 0) {
                $tpl = pathinfo($_SERVER["SCRIPT_FILENAME"]);
                $tpl = "{$tpl['dirname']}/{$tpl['filename']}.tpl";
                if(is_file($tpl)) $this->views[] = $tpl; //adiciona o template por defeito e continua para processa-lo
                unset($tpl);
            }

            if(!$this->cache || $this->generatePage()) {
                $this->body_content = $this->loadViewPart($this->mainView); // Load mainView
                while(empty($this->body_content)) { //retrieve views until last one
                    count($this->views) > 0 || $this->error(404, "There are no views to show.\nIt's impossible return any response! Add at least one view");
                    $this->body_content = $this->loadViewPart(array_shift($this->views));
                }

                $this->manageViews(); //load viewParts  
                $this->body_content = preg_replace( //clean html view
                    array('/<!DOCTYPE[^>]*>/i', "/<\/?html[^>]*>/i", "/<\/?head[^>]*>/i", "/<\/?body[^>]*>/i"),
                    "", 
                    $this->body_content);
                                           
                $this->processSet();
                
                $this->body_content = $this->processFor($this->body_content, '$this->vars');
                $this->processIfElse();
                $this->body_content = preg_replace("/<\/php:(if|else|for|set)\s*>/i", "<?php } ?>", $this->body_content);

                $this->processVars($this->body_content); 
                $this->body_content = preg_replace('/$($\w)/', '$1', $this->body_content); //escape de cifrao

                $this->processHeaders();
                /*
                if($this->minimizeHTML)
                $this->body_content = preg_replace("/\s*(\n|\r|\t)\s+/", "", $this->body_content);
                */
                //$this->body_content = preg_replace(array('/\s+/m', '/>\s*</'), array(" ", "><"), $this->body_content); 
                
                $this->cacheFile(); //save generated html
                $this->source($this->body_content);
            }
            
            $debug = $this->processRequest();      //processa o modelo; obtem as variaveis
            $this->debug($debug); //faz debug ao modelo

            if(isset($_SESSION)) 
                $this->vars['session'] = $this->cleanVar($_SESSION);
            $this->writeResponse();
        }
        

        /**
        * Substitui as tags <php:set
        *         
        */
        private function processSet(){  
            $count = preg_match_all('/<php:set\s+([^\>]+)*>\s*(.*?)\s*<\/php:set>/si', $this->body_content, $set, PREG_OFFSET_CAPTURE);
            if($count > 0) {      
                for( ;$count > 0; $count--) {
                    $replace = "";
                    $it = 1; 
                    $setVar = $this->processVar(rtrim(ltrim($set[1][$count-1][0], "$"))); 
                    $setValue = $set[2][$count-1][0];  
                   
                    if(startsWith("<php:", $setValue) and endsWith("/>", $setValue)) {  
                        if(startsWith("<php:resources", $setValue)) {
                            $replace = $this->processResources($setValue);  
                            $replace = "<?php $setVar = $replace; ?>";                           
                        }                                       
                    } else {            
                        $vars = $this->extractVars($setValue);
                        $arrVars = array();
                        foreach($vars as $pos=>$var) {         /*          
                            $setValue = substr_replace($setValue, "%%$it\$s", $pos, strlen($var)+1);
                            $arrVars[$it++] = $this->processVar($var);   */
                            $setValue = substr_replace($setValue, sprintf("{%s}", $this->processVar($var)), $pos, strlen($var)+1);
                            //$arrVars[$it++] = ;  
                        }                                                
                        $replace = "<?php $setVar = \"$setValue\"; ?>";  
                    }                                             
                    $this->body_content = substr_replace($this->body_content, $replace, $set[0][$count-1][1], strlen($set[0][$count-1][0]));
                }
            }            
        }
        
        private function processResources($content)
        {               
            $count = preg_match_all('/<php:resources(\s*[^\>]+)*>/si', $content, $resources, PREG_OFFSET_CAPTURE);
            if($count > 0) {
                $initVars = "";
                for( ;$count > 0; $count--) {
                    $code = "";
                    $item = $resources[1][$count-1];
                    
                    if(is_array($item)) {
                        $item = trim($item[0], ' /');
                        
                        if(!empty($item)) {
                            
                            $extVars = $this->extractVars($item);
                            foreach($extVars as $extVar) {
                               $procVar = $this->processVar($extVar);
                               $item=str_replace("$$extVar", $procVar, $item);  
                               $initVars = sprintf('%1$s if(!isset(%2$s)) %2$s = "";', $initVars, $procVar);
                            }
                            
                            $code = array();
                            $files = explode(',', $item);
                            foreach($files as $file) {          
                                $code[] = sprintf('$this->internalLoadResources("%s")', trim($file));    
                            }                                                
                        } 
                    }
                    /* $this->body_content = substr_replace($this->body_content, "<?php [$code] ?>", $resources[0][$count-1][1], strlen($resources[0][$count-1][0])); */
                }
                /* $this->body_content = sprintf("<?php %s ?> %s", $initVars, $this->body_content); */
                return count($code) > 1  ? sprintf("array_merge(%s)", implode(",", $code)) : $code[0];                       
            }
        }
        
        private function processHeaders()
        {        
            $meta=array();
            $meta['Content-Type'] = "text/html; charset={$this->encoding}"; //text encoding
            $meta['X-UA-Compatible'] = 'IE=edge,chrome=1';      //IE9 compatibility
            
            $meta['generator'] = $this->name; // PHP Framework 
            $meta["Revisit-After"] = "30 Days";
            $meta["robots"] = "all,index";
            $this->headers['meta'] = $meta;   

            $dynamic_html = "";
            $count = preg_match_all('/<!--\[.+?\]>.*?<!\[endif\]-->/si', $this->body_content, $dynamic, PREG_OFFSET_CAPTURE);
            if($count > 0) {       
                $dynamic = $dynamic[0];  
                for($i = $count-1; $i >= 0; $i--) {
                    $this->body_content = substr_replace($this->body_content, "", $dynamic[$i][1], strlen($dynamic[$i][0]));
                    $dynamic_html = "{$dynamic[$i][0]}$dynamic_html";
                }                                         
            }           
            $this->headers['dynamic'] = $dynamic_html;

            $count = preg_match_all('/<title.*?>.*?<\/title>/si', $this->body_content, $title, PREG_OFFSET_CAPTURE);
            if($count > 0) {
                $title = $title[0];
                for($i = $count-1; $i > -1; $i--) 
                    $this->body_content = substr_replace($this->body_content, "", $title[$i][1], strlen($title[$i][0]));
            }

            if(!empty($this->title))
                $this->head_content = "{$this->head_content}<title>{$this->title}</title>"; //adiciona titulo
            else if($count > 0)
                $this->head_content = "{$this->head_content}{$title[$count-1][0]}"; 
            
                $count = preg_match_all('/<(meta|link).*?[^-\?]>/si', $this->body_content, $meta, PREG_OFFSET_CAPTURE);
            if($count > 0) {
                $meta = $meta[0];
                for($i = $count-1; $i >= 0; $i--) {
                    $this->body_content = substr_replace($this->body_content, "", $meta[$i][1], strlen($meta[$i][0]));
                    $this->head_content = "{$meta[$i][0]}{$this->head_content}";
                }
            }
            
            $count = preg_match_all('/<style.*?>(.*?)<\/style>/si', $this->body_content, $style, PREG_OFFSET_CAPTURE);
            if($count > 0) {           
                for($i = $count-1; $i > -1; $i--) {
                    $this->body_content = substr_replace($this->body_content, "", $style[0][$i][1], strlen($style[0][$i][0]));
                    $this->headers['style'] = "{$style[1][$i][0]}{$this->headers['style']}"; 
                }
                $this->headers['style'] = preg_replace(array('/\s*\r\n\s*/',  '/\s*\/\*.*?\*\/\s*/'), '', $this->headers['style']); //remove comentarios
            }

            $count = preg_match_all('/<script(.*?[^-\?])\/?>(?:(.*?)<\/script>)?/si', $this->body_content, $script, PREG_OFFSET_CAPTURE);
            if($count > 0) {
                //$script = $script[0];
                for($i = $count-1; $i >= 0; $i--) 
                {
                    $phpCount = preg_match_all('/<\?php\s+[^?]+\?>/i', $script[1][$i][0], $encScript);
                    if($phpCount > 0)
                        $script[1][$i][0] = str_replace($encScript[0], '%s', $script[1][$i][0]);

                    if(preg_match('/(?:src|href)=([\'"])(.*?)\1/si', $script[1][$i][0], $src))
                    {                             
                        if(!preg_match("/^(ht|f)tps?:\/\//", $src[2]) && !in_array($src[2][0], array('/', '%'))) {
                            $src[2] = str_replace($this->document_root, "", "{$this->script_path}/{$src[2]}");
                            $src[2] = preg_replace("/[A-Za-z0-9%]+(\/|\\\\)+\.\.(\/|\\\\)+/", "", $src[2]);
                        }
                        if($phpCount > 0)
                            $src[2] = vsprintf($src[2], $encScript[0]);

                        $this->headers['script'][] = "<script type='text/javascript' src='{$src[2]}'></script>";

                    } else if(!empty($script[2][$i][0]))
                        $this->headers['script'][] = "<script type='text/javascript'>{$script[2][$i][0]}</script>";                    

                        $this->body_content = substr_replace($this->body_content, '', $script[0][$i][1], strlen($script[0][$i][0]));
                }
                $this->script_body = preg_replace(array('/\/\/[^\\n]*\\n/', '/\s*\r\n\s*/', '/\s*\/\*[^*]*\*\/\s*/'), '', $this->script_body); //remove comentarios
            }                
            $this->headers['script'] = array_unique(array_reverse($this->headers['script']));                                           

            if(isset($this->icon)) {
                $pathIcon = $this->icon[0]=='/' ? $this->document_root : dirname($this->script_path);
                $pathIcon = "$pathIcon/{$this->icon}";
                if(is_file($pathIcon))
                    $this->head_content = "{$this->head_content}<link rel='shortcut icon' href='{$this->icon}'/>";
            }
        }

        private function manageViews()
        {
            while(preg_match("/<php:view\s*([^>]*)>/i", $this->body_content, $view, PREG_OFFSET_CAPTURE))
            {
                $view_name = trim($view[1][0], '/ ');
                if($view_name=='')
                    $view_name = $this->currentView++;

                $htmlPart = "";
                if(array_key_exists($view_name, $this->views))
                    $htmlPart = $this->loadViewPart($this->views[$view_name]);

                $this->body_content = substr_replace($this->body_content, $htmlPart, $view[0][1], strlen($view[0][0]));
            }
        }

        /**
        * read a viewPart and return its content
        * 
        * @param string relative path for view
        */
        private function loadViewPart($tpl)
        {
            $html="";
            if(is_string($tpl) && (is_file($tpl) || is_file($tpl = "$tpl.tpl"))) //se existir template
                $html = file_get_contents($tpl); //le conteudo do template 
            return $html;    
        }

        /**
        * generate the meta headers by name
        */
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

        /**
        * write the generated page to browser
        * 
        * @param string Name of page
        */
        private function writeResponse($file="")
        {
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

                    default: echo $content;
                }
            }    
        }

        /**
        * All tags php:for are processed do php code
        * 
        * @param string Text to process
        * @param string Variable name that act as Array
        */
        private function processFor(&$htmlToProcess, $arrayName)
        {
            while(preg_match("/<php:for\s+([^>]+)>/i", $htmlToProcess, $for))
            {
                if($for[1]!="" && preg_match('/(.*)\s+(as|in)\s+(.*)/', $for[1], $tmpVar))
                {
                    if($tmpVar[2]=='in')
                        $forVars = array(trim($tmpVar[3],'$ '), trim($tmpVar[1]));
                    else
                        $forVars = array(trim($tmpVar[1],'$ '), trim($tmpVar[3]));

                    $forVars[0] = str_replace('?', '_', $forVars[0]);
                    //$arrayItemName = $forVars[0];

                    if(preg_match("/\[([^\.\.]+)\.\.([^\]]+)\]\s*,?\s*(.*)/i", $forVars[0], $range))
                    {
                        $min =  str_replace('$??', '$__', $range[1]);
                        $max =  str_replace('$??', '$__', $range[2]);
                        $step = str_replace('$??', '$__', $range[3]);
                        $forVars[0] = ltrim($max, ' $?'); 
                        /*
                        $replace = sprintf(
                        "%1\$s=\"$step\"; 
                        if(empty(%1\$s) ? true : is_numeric(%1\$s)) 
                        foreach(range($min, empty(%1\$s) ? $max : abs($max-$min)<%1\$s ? $min : $max %2\$s) as", 
                        "\$__{$forVars[0]}_rangeFn",  empty($step) ? "" : ", $step"); 
                        */
                        $replace = sprintf(
                            '%1$s = intval("%2$s"); if(empty(%1$s) ? true : is_numeric(%1$s)) foreach(range(%3$s, empty(%1$s) ? %4$s : abs(%4$s - %3$s)<(%1$s ? %3$s : %4$s) %5$s) as', 
                            "\$__{$forVars[0]}_rangeFn", $step, $min, $max, empty($step) ? "" : ", $step"); 
                        unset($range, $max, $step);
                    } else {
                        if(strpos($forVars[0], ".")>0)
                        {
                            $arrArgs = explode(".", $forVars[0]);
                            $subArrVar = array_shift($arrArgs);
                            $subArrArgs = implode('"]["', $arrArgs);
                            //$arrayItemName = sprintf('$%s["%s"]', $subArrVar, $subArrArgs);
                            $replace = sprintf(
                                'if(isset($%2$s["%1$s"]) && is_array($%2$s["%1$s"])&& count($%2$s["%1$s"])>0) foreach($%2$s["%1$s"] as',
                                $subArrArgs, $subArrVar);
                            $forVars[0] = str_replace(".", "_", ltrim($forVars[0], '_'));
                        } else
                            $replace = sprintf(
                                'if(isset(%2$s["%1$s"]) && is_array(%2$s["%1$s"])&& count(%2$s["%1$s"])>0) foreach(%2$s["%1$s"] as', 
                                $forVars[0], $arrayName);
                    }
                    $forOldVar=explode(",", $forVars[1]); 
                    /*
                    $replace = sprintf(
                    'if(array_key_exists("%1$s", $this->vars) && is_array($this->vars["%1$s"])&& count($this->vars["%1$s"])>0) foreach($this->vars["%1$s"] as', 
                    $forVars[0]);
                    */
                    $forVarsItems = count($forOldVar); 
                    for($forIti = 0; $forIti < $forVarsItems; $forIti++)
                        $forOldVar[$forIti] = trim($forOldVar[$forIti], ' $?');

                    if($forVarsItems>1)
                        $replace="$replace \$__{$forVars[0]}_{$forOldVar[0]} =>";

                    $localVar = sprintf('$__%s_%s', $forVars[0], $forOldVar[$forVarsItems==1 ? 0 : 1]);
                    $replace="$replace $localVar) {";

                    if($forVarsItems>2)
                        $replace="\$__{$forVars[0]}_{$forOldVar[2]}=-1; $replace \$__{$forVars[0]}_{$forOldVar[2]}++;";

                    $fiPos = strpos($htmlToProcess, $for[0])+strlen($for[0]);
                    $fePos = strpos($htmlToProcess, "</php:for", $fiPos)+10;
                    $subFors = substr_count($htmlToProcess, "<php:for", $fiPos, $fePos-$fiPos-10);

                    for($it=0; $it<$subFors; $it++)
                        $fePos = strpos($htmlToProcess, "</php:for", $fePos)+10;

                    $forHtml = substr($htmlToProcess, $fiPos, $fePos-$fiPos-10);

                    $forNewVar = array();
                    for($forIti = 0; $forIti < $forVarsItems; $forIti++){
                        $forNewVar[$forIti] = "\$??{$forVars[0]}_{$forOldVar[$forIti]}\$1";
                        $forOldVar[$forIti] = "/\\\$(?:\?\?)?{$forOldVar[$forIti]}([^a-zA-Z0-9_])/";
                    }  

                    $forNewHtml = preg_replace($forOldVar, $forNewVar, $forHtml);
                    if($subFors > 0) 
                        $forNewHtml = $this->processFor($forNewHtml, $localVar);

                    $htmlReplace = str_replace("{$for[0]}$forHtml</php:for>", "<?php $replace ?>$forNewHtml<?php } ?>", $htmlToProcess);

                    if($arrayName == '$this->vars')
                        $htmlToProcess = $htmlReplace;
                    else  
                        return $htmlReplace;
                } else 
                    throw new Exception("Cannot handle tag on '{$for[0]}'");
            } 
            return $htmlToProcess;  
        }

        /**
        * Process all php:if or php:else in generated view to PHP code
        */
        private function processIfElse()
        {              
            $replaces = array(
                "/\s+is\s+even\s+by\s+(\d+)/"       => ' % $1 == 0',
                "/\s+is\s+even/"                    => ' % 2 == 0',
                "/\s+is\s+empty/"                   => ' == ""',
                "/\s+is\s+not\s+even\s+by\s+(\d+)/" => ' % $1 != 0', 
                "/\s+is\s+not\s+even/"              => ' % 2 != 0', 
                "/\s+is\s+not\s+empty/"             => ' != ""', 
                "/\s+neq\s+/"                       => " != ",
                "/\s+is\s+not\s+/"                  => " != ", 
                "/\s+eq\s+/"                        => " == ",
                "/\s+is\s+/"                        => " == ", 
                "/\s+lt\s+/"                        => " < ", 
                "/\s+lte\s+/"                       => " <= ", 
                "/\s+gt\s+/"                        => " > ", 
                "/\s+gte\s+/"                       => " >= ", 
                "/\s+and\s+/"                       => " && ", 
                "/\s+or\s+/"                        => " || ", 
                "/\s+not\s+/"                       => " !", 
                "/\s+mod\s+/"                       => " % "
            );
            $replaces_keys = array_keys($replaces);
            $replaces_values = array_values($replaces);

            $testVarsCode = array();              

            while(preg_match("/<php:(if|else)([^>]*)>/i", $this->body_content, $ifelse)) { 
                $ifelse[2] = trim($ifelse[2], " \/");
                $ifelse[2] = preg_replace($replaces_keys, $replaces_values, $ifelse[2]);

                if(!empty($ifelse[2])) {
                    $vars = $this->extractVars($ifelse[2]);

                    $ifTestCode = "";
                    if(count($vars)>0) {
                        foreach($vars as $i=>$var) {
                            $varClass = $this->processVar($var);
                            $ifelse[2] = substr_replace($ifelse[2], $varClass, $i, strlen($var)+1);
                            $vars[$i] = $varClass;
                        }
                        $u_vars = array_unique($vars);

                        foreach($u_vars as $u_var) {
                            $arrayInit = 0;
                            $test_vars = "";
                            while(($arrayPos = strpos($u_var, '][', $arrayInit)) != false) {
                                $arrayVar = substr($u_var, 0, $arrayPos+1);
                                $test_vars = sprintf('%1$s if(!is_array(%2$s) && !is_scalar(%2$s)) %2$s = array();', $test_vars, $arrayVar);
                                $arrayInit = $arrayPos + 2;                              
                            }
                            $test_vars = sprintf('%1$s if(!isset(%2$s)) %2$s = null;', $test_vars, $u_var);

                            if(!startsWith('$__', $u_var)) {
                                if(!in_array($test_vars, $testVarsCode))
                                    $testVarsCode[] = $test_vars;
                            } else {
                                $ifTestCode = "$ifTestCode $test_vars";
                            }
                        }                    
                    }
                }
                if($ifelse[1]=="else")
                    $ifelse[2] = empty($ifelse[2]) ? "} else" : "} else if({$ifelse[2]})";
                else {
                    if(empty($ifelse[2])) new ErrorException("IF has null arguments", 500);
                    $ifelse[2] = "$ifTestCode if({$ifelse[2]})";
                }
                $this->body_content = str_replace($ifelse[0], "<?php $ifelse[2] { ?>", $this->body_content);
            }
            $this->body_content = sprintf("<?php %s ?>\n%s", implode($testVarsCode), $this->body_content);
        }

        /**
        * Found all variables in text
        * 
        * @param string Text that have/or not variables
        * @return array The name of variables found in the text
        */
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

        /**
        * Process all view variables found and replace them in original code
        * 
        * @param string Text to process
        */
        private function processVars(&$html)
        { /* Replacing ordinary variables */
            //"/([^\\\$])(\\\$([a-z0-9_\.\?]+))(|[a-z_]+(:[a-z0-9_\"'])?)?(}?)/i"
            $count = preg_match_all('/(([^\$])\s*)\$([a-z0-9\?]{1,2}[a-z0-9_\.]*)(?:\s*\|\s*([^\}]+))?(\})?/i',$html, $var, PREG_OFFSET_CAPTURE);
                 
            if($count > 0){
                $functions = array(
                    'upper' => 'strtoupper',
                    'lower' => 'strtolower',
                    'html' => 'html_entity_decode'
                );

                for($i=$count-1; $i>=0; $i--) {
                    if($var[3][$i][0]=="this") 
                        continue;

                    if($var[2][$i][0]=="{" && is_array($var[5][$i]) && $var[5][$i][0]=="}") $var[1][$i][0]="";
                    $replace = $this->processVar($var[3][$i][0], true);
    
                    if(empty($var[4][$i][0]) || !array_key_exists(trim($var[4][$i][0]), $functions))
                        $replace = "{$var[1][$i][0]}<?php if(isset($replace) && is_scalar($replace)) echo $replace; ?>";
                    else
                        $replace = sprintf('%1$s<?php if(isset(%2$s) && is_scalar(%2$s)) echo %3$s(%2$s); ?>', $var[1][$i][0], $replace, $functions[trim($var[4][$i][0])]);    

                    $html = substr_replace($html, $replace, $var[0][$i][1], strlen($var[0][$i][0]));
                }
            }
        }

        private function processVar($varName)
        {
            if(substr($varName, 0, 2)!="??")
                $replace = '$this->vars["%1$s"]%2$s'; //sprintf('%s %s', $outputVar ? 'if(array_key_exists("%1$s", $this->vars) && is_scalar($this->vars["%1$s"]%2$s)) echo' : '', '$this->vars["%1$s"]%2$s');
            else{
                $replace = '$__%1$s%2$s'; //sprintf('%s %s', $outputVar ? 'if(is_scalar($__%1$s%2$s)) echo' : '', ' $__%1$s%2$s');
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
                //$replace = str_replace('this->vars)', 'this->vars) && isset($this->vars["%1$s"]%2$s)', $replace);
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
            $folderCache = "{$this->document_root}/{$this->cacheDir}";                                         
            if(is_dir($folderCache) || mkdir($folderCache, 0777, true))
            {              
                $debug_css = "<style type='text/css'>fieldset.loaderphp_debug{border:thin solid blue;margin-top:2px;}div.loaderphp_debug,textarea.loaderphp_debug{width:100%;margin-right:2px;max-height:250px;}div.loaderphp_debug{overflow:auto;}</style>";    

                $content = sprintf("<!-- %s -->\r\n<!DOCTYPE html>\r\n<html>\r\n<head>\r\n%s\r\n%s\r\n%s\r\n%s\r\n</head>\r\n<body>\r\n%s\r\n%s\r\n%s%s\r\n</body>\r\n</html>",  
                    $this->debug ? $_SERVER['PHP_SELF'] : "Generated by {$this->name}/{$this->version}",  
                    $this->generateMetaHeaders(),
                    $this->head_content, 
                    "<style type='text/css'>{$this->headers['style']}</style>",
                    "<?php if(\$this->debug) echo \"$debug_css\"; ?>",                             
                    '<?php if($this->debug) echo $this->debugMsg; ?>', 
                    $this->body_content,
                    implode($this->headers['script']),
                    $this->headers['dynamic']
                );      

                $content = preg_replace(array("/\?>\s+<\?php/", "/\r\n\s+\r\n/"), array(''), $content);
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
            $folderCache = "{$this->document_root}/{$this->cacheDir}"; //pasta onde guarda os ficheiros de cache
            $cacheFilename = rtrim(base64_encode($_SERVER['PHP_SELF']), "="); //gera nome unico para cada ficheiro   
            return "$folderCache/$cacheFilename.php";
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
                403 => "Forbiden",
                '404' => "Not Found",
                '405' => "Method Not Allowed",
                '500' => "Internal Server Error", 
            );
            header("HTTP/1.1 $code {$errors[$code]}");
            die("<!DOCTYPE html><html><head><meta http-equiv='Content-Type' content='text/html; charset={$this->encoding}' /><title>$code {$errors[$code]}</title></head>
                <body><center><h4>$msg</h4>\n<hr/>{$this->name}/{$this->version}</center></body></html>");   
        }     
        private function debug($var, $title="")
        {   
            if(!empty($var))
                $this->debugMsg = sprintf("%s<fieldset class='loaderphp_debug'>%s<div class='loaderphp_debug'>%s</div></fieldset>",
                    $this->debugMsg, empty($title) ? "" : "<legend style='color:blue'>$title</legend>", is_array($var) ? var_export($var, true) : $var);
        }
        private function source($var, $title="")
        {
            if(!empty($var))
                $this->debugMsg = sprintf("%s<fieldset class='loaderphp_debug'>%s<textarea readonly='readonly' class='loaderphp_debug' rows='15'>%s</textarea></fieldset>",
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
        if(!(is_bool($case_sensitive) && $case_sensitive)) {
            $substr = strtolower($substr);
            $str = strtolower($str); 
        }
        return substr($str, 0, strlen($substr)) == $substr;
    }
    function endsWith($substr, $str, $case_sensitive = FALSE){
        if(!(is_bool($case_sensitive) && $case_sensitive)) {
            $substr = strtolower($substr);
            $str = strtolower($str); 
        }
        return substr($str, 0-strlen($substr)) == $substr;
    }

    final class Database{               
        const ASSOC = "ASSOC";
        const NUM = "NUM";
        const BOTH = "BOTH";

        private $driver;
        private $conn;
        //private $errorLog;
        private $result;
        private $debug = FALSE;
        private $persistentConn = false;

        public static function getClassName(){
            return get_class();
        }
        
        public function setDebug($debug = true){
            $this->debug = $debug;
        }

        public function usePersistentConnection($persistent = true){
            $this->persistentConn = (bool) $persistent;
        }

        public function __set($name, $value){
            return;
        }  

        public function __get($name){
            return $this->$name;
        }

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
                            mysqli_options($this->conn, MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 1');
                            mysqli_options($this->conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
                            mysqli_real_connect($this->conn, $host, $username, $password, $database, $port);
                        }
                        if($this->conn){ //is really connected
                            if(mysqli_select_db($this->conn, $database)) {
                                error_log("MySQL error: database '$database' don't exist.");
                                die("MySQL error: database '$database' don't exist.");
                            }
                            return;
                        } else
                            $this->connectError(mysqli_connect_error(), mysqli_connect_errno());
                    } else
                        error_log("Extension not loaded: mysqli !\n Trying next extension!");
                case "mysql":
                    if(extension_loaded("mysql")){
                        $this->driver = "mysql";
                        $this->conn = call_user_func_array($this->persistentConn ? 'mysql_pconnect' : 'mysql_connect', array("$host:$port", $username, $password));
                        if($this->conn) {
                            mysql_select_db($database, $this->conn) || $this->errorDie("MySQL error: database '$database' don't exist.");
                            break;
                        } else 
                            $this->connectError(mysql_error(), mysql_errno());    
                    }
                default:
                    $this->errorDie("ERROR: Can't connect to database!");
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
                        if(($this->conn = sqlite_open($filename, 0666, $error)) == FALSE)
                            $this->connectError($error);
                        else
                            break;
                    }
                default:
                    $this->errorDie("SQlite Error: Can't connect to database!");
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
            $this->InterBase($host, $database, $username, $password);
        }

        /**
        * Open connection with InterBase Database Server
        * 
        * @param string Hostname or IP Address
        * @param string DB alias or DB filepath
        * @param strin DB Username
        * @param string DB password
        */
        public function InterBase($host="127.0.0.1", $database, $username, $password){ 
            $this->getDriver("interbase");
            if($this->driver == "interbase")
                $this->conn = call_user_func_array($this->persistentConn ? 'ibase_pconnect' : 'ibase_connect', array("$host:$database", $username, $password));
            else 
                $this->loadExtensionError("interbase");
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
                case 'integer':
                    $sql = str_replace(":1", $this->escapeString($params), $sql); 
                    break;
                case "string":  
                    $sql = str_replace(":1", "'{$this->escapeString($params)}'", $sql); 
                    break;
                case "array": 
                    if(count($params)>0)     
                    {    
                        for($i=count($params); $i>0; $i--) 
                        {           
                            $escapedParam = $this->escapeString($params[$i-1]);
                            $escapedParam = is_integer($params[$i-1]) ? $escapedParam : "'$escapedParam'";
                            if(is_scalar($escapedParam))
                                $sql = str_replace(":$i", $escapedParam, $sql); 
                        }
                    }
            }
            return $sql;
        }

        /**
        * Execute a query and return the number of affected rows
        * 
        * @param string query sql
        * @param string|int|array 
        * @return int|null
        */
        function execute($sql, $params=NULL)
        {
            if(!is_string($sql) || empty($sql))
                return $this->result = NULL;

            if(startsWith("select", $sql))
                return $this->query($sql, $params);

            if(!is_null($params))
                $this->replaceParams($sql, $params);

            if($this->debug) echo "<span style='display:block; width:100%'>Execute: ", $sql, "</span>";
            switch($this->driver){
                case 'sqlite' :
                    if(sqlite_exec($this->conn, $sql, $error))
                        return sqlite_changes($this->conn);
                    else {
                        error_log($error);
                        return false;
                    }   
                    break;
                case 'sqlite3' :
                    return $this->conn->exec($sql);   
                    break;
                case 'interbase':
                    ibase_query($this->conn, $sql);
                    return ibase_affected_rows($this->conn);
                    break;
                case 'mysqli':
                    return mysqli_query($this->conn, $sql) ? mysqli_affected_rows($this->conn) : false; 
                    break;
                case 'mysql':
                    mysql_query($sql, $this->conn);
                    return mysql_affected_rows($this->conn);
            }
        }
        
        /**
        * Resets the result set back to the first row
        * @return boolean Operation result
        */                                           
        function reset(){
             switch($this->driver){
                case 'sqlite'   : return sqlite_rewind($this->result);
                case 'sqlite3'  : return $this->result->reset();
                case 'interbase': return false;
                case 'mysqli'   : 
                    if(mysqli_num_rows($this->result) == 0)
                        return mysqli_data_seek($this->result, 0);
                    return true;
                case 'mysql': 
                    if(mysql_num_rows($this->result) == 0)
                        return mysql_data_seek($this->result, 0);
                    return true;
            }
            return false;  
        }

        /**
        * Execute query against a database
        * 
        * @param string
        * @param mixed
        * @return integer Number of rows as result of query  / false in cause of failure 
        */
        function query($sql, $params=NULL)
        {
            if(!is_string($sql) || empty($sql))  {
                error_log("Invalid query");
                return $this->result = NULL;
            }

            if(!startsWith("select", $sql))
                return $this->execute($sql, $params);

            if(!is_null($params))
                $this->replaceParams($sql, $params);

            if($this->debug)
                echo "<span style='display:block; width:100%'>Query: ", $sql, "</span>";

            $this->result = false;
            switch($this->driver){
                case 'sqlite' :
                    if($this->result = sqlite_unbuffered_query($this->conn, $sql, SQLITE_BOTH, $error_msg)) 
                        return sqlite_num_fields($this->result); 
                        
                    error_log($error_msg);
                    break;
                case 'sqlite3' :
                    if($this->result = $this->conn->query($sql))
                        return $this->result->numColumns();
                    
                    error_log($this->conn->lastErrorMsg()); 
                    break;
                case 'interbase':
                    if($this->result = ibase_query($this->conn, $sql))
                        return ibase_num_fields($this->result);
                        
                    error_log(ibase_errmsg());       
                    break;
                case 'mysqli':
                    if($this->result = mysqli_query($this->conn, $sql)) 
                        return mysqli_num_rows($this->result); 
                    
                    error_log(mysqli_error($this->conn));
                    break;
                case 'mysql':
                    if($this->result = mysql_query($sql, $this->conn))
                        return mysql_num_rows($this->result);
                    
                    error_log(mysql_error($this->conn));
                    break;
            }
            return false;                      
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
                    return mysqli_real_escape_string($this->conn, $param);
                case 'mysql':
                    return mysql_real_escape_string($param);
                case 'sqlite3': 
                    return $this->conn->escapeString($param);
                case 'sqlite':
                    return sqlite_escape_string($param);
                case 'interbase':
                    return addslashes(trim($param, " '"));    
            }
        }

        /**
        * Fetch one row from query result as array
        * 
        * @param string ASSOC|NUM
        */
        function fetch($result_type='ASSOC')
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
        function fetchAll($maxResults=0, $result_type='ASSOC')
        {
            $results = array();
            if($this->result == false) return $results;

            $it=0;
            switch($this->driver)
            {
                case 'mysqli':
                    $rtype = constant("MYSQLI_$result_type");   
                    while(($row = mysqli_fetch_array($this->result, $rtype)) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = $row;
                    break;
                case 'mysql':
                    $rtype = constant("MYSQL_$result_type");
                    while(($row = mysql_fetch_array($this->result, $rtype)) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = $row;
                    break;
                case 'sqlite3': 
                    $rtype = constant("SQLITE3_$result_type");  
                    while(($row = $this->result->fetchArray($rtype)) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = $row;
                    break;
                case 'sqlite':
                    $rtype = constant("SQLITE_$result_type");
                    while(($row = sqlite_fetch_array($this->result, $rtype)) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = $row;
                    break;
                case 'interbase':
                    $rtype = constant("IBASE_$result_type");
                    while(($row = ibase_fetch_array($this->result, $rtype)) != FALSE && ($maxResults == 0 || $maxResults > $it++))
                        $results[] = array_change_key_case($row);
                    break;
            }             
            return $results; 
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
        /*
        function connectFromFile($data)
        {
        if(!is_null($this->supportedDBs($data['server'])))
        {
        if(array_key_exists('persistent', $data)) 
        $this->usePersistentConnection($data['persistent']);

        switch($data['server'])
        {
        case 'mysql':
        case 'mysqli':
        $params = array(empty($data['host']) ? '127.0.0.1' : $data['host'], $data['database'], $data['username'], $data['password'], empty($data['port']) ? 3306 : $data['port']);
        break;
        case 'firebird':
        case 'interbase':
        $params = array(empty($data['host']) ? '127.0.0.1' : $data['host'], $data['database'], $data['username'], $data['password']);
        break;
        case 'sqlite3':
        case 'sqlite':
        $params = array($data['filename'], $data['key']);
        }
        call_user_func_array(array($this, $data['server']), $params);
        }
        }
        */
        private function queryError(){
            $msg = "Query to BD has failed!";

            switch($this->driver){
                case 'mysqli': 
                    $msg = sprintf("%s\n%d: %s", $msg, mysqli_errno($this->conn), mysqli_error($this->conn));
                    break;

                default: 
                    $msg = "$msg\n No driver to connect DB and execute querys.";
            }
            error_log($msg);
        }
                                        
        private function errorDie($msg=''){
            error_log($msg);
            die($msg);
        }

        private function loadExtensionError($drvName=null) {
            if(isset($drvName))   
                error_log("Error loading driver: $drvName");
            die("ERROR: Connecting BD");
        }
        
        private function connectError($msg, $code=''){
            $server = array(
                "mysql" => "MySQL",
                "sqlite" => "SQlite",
                "interbase" => "InterBase",
                "firebird" => "Firebird",
                "oracle" => "Oracle"
            );
            $error = sprintf('%s connect error%s: %s', $server[$this->driver], empty($code) ? '' : " #$code", $msg);
            error_log($error);
            die($error);
        }

        function __destruct (){
            if($this->conn != null && !$this->persistentConn) {
                switch($this->driver){
                    case 'sqlite' :     
                        sqlite_close($this->conn);
                        break;
                    case 'sqlite3' :   
                        $this->conn->close();
                        break;
                    case 'interbase':
                        ibase_free_result($this->result);
                        ibase_close($this->conn);
                        break;
                    case 'mysqli':  
                        mysqli_free_result($this->result);
                        mysqli_close($this->conn);
                        break;
                    case 'mysql':
                        mysql_free_result($this->result);
                        mysql_close($this->conn);
                }
                unset($this->result, $this->conn);
            }
        }

        public static function supportedDBs($name=''){
            $dbs = array(
                "mysql" => array("mysqli", "mysql"),
                "sqlite" => array("v2"=>"sqlite", "v3"=>"sqlite3" ),
                "interbase" => "interbase",
                "firebird" => "interbase",
                //"postgresql" => "pgsql",
                "oracle" => array("11g"=>"oci8_11g", "10g"=>"oci8"),
                //"ms_sql_server" => "mssql",
                //"mongodb" => "mongo"
            );
            return empty($name)/* || !array_key_exists($name, $dbs) */? $dbs : $dbs[$name];        
        }

        private static function loadExtension($extName){
            if(!extension_loaded($extName) && strtolower(ini_get("enable_dl")) == "on"){
                dl(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? "php_$extName.dll" : "$extName.so");
            }
            return extension_loaded($extName);
        }
    }       
     
    abstract class Entity {
                                                   
        private $bdname = null;
        private $columns = array();    
        private $values = array();
        private $relations = array();
        private static $connetion = null; 
        private $primaryKey; 
        private $orderBy = array();
        private $operation = null;
        
        public final function __construct() {  
            $this->bdname = get_class($this);     
            if(defined("{$this->bdname}::NAME"))
                $this->bdname = constant("{$this->bdname}::NAME");   
            if(defined("{$this->bdname}::name"))
                $this->bdname = constant("{$this->bdname}::name");  
                
            $this->primaryKey = $this->primaryKey();
                      
            foreach(get_class_vars($this->bdname) as $var => $column) {
                if(is_string($column) && !empty($this->$var)) {     
                    $this->columns[$var] = $column;        
                    unset($this->$var);
                }                                   
            } var_dump($this->columns);                                      
        }                       
          /*
        private function checkClassStatus(){
            $return = false;
            if(!isset($this->bdname)) 
                error_log(sprintf("ERROR: Bad class '%s' initialization.", get_class($this)));
            else if(count($this->columns) == 0) 
                error_log(sprintf("ERROR: Entity '%s' without columns.", get_class($this)));  
            else 
                $return = true;
           
           return $return;
        }
        */
        
        public final static function connection($conn) {      
            if(is_a($conn, Database::getClassName())) 
                Entity::$connetion = $conn;
            else
                error_log("Wrong database connection. Required Database instance");
        }
        
        public function primaryKey(){  
            return null;           
        }
        
        public function insert(){ 
            "insert into %s (%s) values (%s)";
                      
        }
            
        public final function toArray() { 
            if($this->operation == 'select') {   //var_dump(Entity::$connetion->fetchAll());   
                return Entity::$connetion->fetchAll();   
            }
            return array();
        }
        
        public final function select() {   
          
            $filter = array();
            foreach($this->values as $key => $value)
                $filter[] = sprintf("%s = '%s'", $key, str_replace("'", "''", $value));   
                
            $query = sprintf("select %s from %s %s %s", 
                implode(', ', $this->columns),  
                $this->bdname,                                                           
                count($filter) > 0 ? sprintf('where %s', implode(' and ', $filter)) : '',
                count($this->orderBy) > 0 ? sprintf('order by %s', implode(' and ', $this->orderBy)) : ''
            );                       
              var_dump($query);            
            if(Entity::$connetion != null && Entity::$connetion->conn != null) {
                $this->operation = 'select';      
                return Entity::$connetion->query($query);              
            }                          
        }
        
        public final function orderBy(){
            switch(func_num_args()) {
                case 0:    
                    $this->orderBy = array();
                    break;
                case 1:
                    $arg = func_get_arg(0);             
                    switch(gettype($arg)){
                        case 'array' :
                            $this->orderBy = $arg;
                            break;
                        case 'string' :
                            $this->orderBy = array($arg);
                            break;
                        case 'integer' :  
                            if($arg <= count($this->columns))
                                $this->orderBy = array($arg);
                            break;
                        default:                         
                            $this->orderBy = array();
                    }
                    break;  
                default:                          
                    $this->orderBy = $arg;               
            } 
            var_dump($this->orderBy)  ;    
        }
        
        protected function addRelation($localColumn, $remoteColumn) {
            if(in_array($localColumn, $this->columns))
                $this->relations[$localColumn] = $remoteColumn;            
        }
        
        public function update() {
            
        }
        
        public final function __set($name, $value){    
            if(array_key_exists($name, $this->columns))
                $this->values[$this->columns[$name]] = preg_replace('/^%([^%].+[^%])%$/', '%%$1%%', $value);                                                              
        }
        
        public final function __get($name){
            if(array_key_exists($name, $this->columns) && array_key_exists($this->columns[$name], $this->values))
                return $this->values[$this->columns[$name]];   
            else if(!isset($this->operation))
                return $this->columns[$name];
            else
                return null;         
        }
        
        public function next() { 
            if($this->operation == 'select')
                $this->values = Entity::$connetion->fetch();
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
        if($result) {
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
    }
?>