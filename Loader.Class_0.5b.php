<?php
    ini_get("date.timezone") || ini_set("date.timezone", "Europe/London"); 
    //error_reporting(E_ERROR); //production
    error_reporting(E_ALL); //development
    
    ini_set('display_errors', "1");
    ini_set('display_startup_errors', true);
    ini_set('log_errors', true);
                                          
    ini_set('error_log', "{$_SERVER['DOCUMENT_ROOT']}/loader.log");
    ini_set("error_prepend_string", "\n<fieldset class='loaderphp_error' style='border:thin solid red;'><legend style='color:red;'>ERROR</legend>");
    ini_set("error_append_string", "\n</fieldset>");

    function __autoload($class_name) 
    {  
        if(is_file("models/$class_name.php"))
            include_once("models/$class_name.php");
    }
 
    $funct = create_function("", "ob_start(); if(function_exists('unload')) call_user_func('unload'); ob_end_clean();");
    register_shutdown_function($funct);

    final class Loader
    {      
        private $name="LoaderPHP Framework";
        private $version="0.5b";

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
        private $isXHR;
        
        /**
        * Registered ajax functions
        */
        private $ajaxFunctions = array();

        /**
        * export variables to view
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
        private $title='';         
        // private $var;  
        
        public function set()
        { 
            $args = func_get_args(); 
            
            switch(gettype($args[0])) 
            {
                case 'array' :
                    if(func_num_args() == 1) 
                        foreach($args[0] as $k=>$v)                        
                            $this->_set($k, $v);
                break;
                case 'string' :   
                    if(func_num_args() == 2)                             
                        $this->_set($args[0], $args[1]);
                     break; 
            }  
        }
        private function _set($var, $value)
        { 
            $var = strtolower($var);
            switch($var) 
            {
                case 'ajaxfunctions' :
                    $this->ajaxFunctions = $value;
                    break;  
                case 'mainview' :
                    $this->setMainView($value);
                    break;  
                default:
                    if(gettype($value) == gettype($this->$var)) 
                        $this->$var = $value; 
            }
        }
                
        public function __isset($name) { return isset($this->vars[$name]); }
        public function __unset($name) { unset($this->vars[$name]); }
        public function __set($name, $value="") 
        {            
            //$noVars = array('files', 'cookie', 'session', 'get', 'post');
            if(is_string($name) && strlen($name)>0 && substr($name, 0, 2)!="__")
            {
                $this->vars[$this->cleanVar($name)] = is_array($value) ? $this->cleanVar($value) : $value;
                return true;
            }
            error_log("Invalid var name: '$name'");
            return false;
        }
        public function __get($name) 
        { 
            if(is_string($name) && !empty($name))
                return array_key_exists($name, $this->vars) ? $this->vars[$name] : ""; 
                
            return "";
        }

        private function cleanVar($nameVarOrArrayVars)
        {                                 
            //$pattern = '/[^a-zA-Z0-9_]/';
            $pattern = '/^\W$/';
            if(is_array($nameVarOrArrayVars))
            {
                $tmpArray = array();
                foreach($nameVarOrArrayVars as $key=>$val)
                {
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

        public function __construct() 
        {
            $this->method = $method = strtolower($_SERVER["REQUEST_METHOD"]); //metodo HTTP usado
            in_array($method, array('get', 'post'))  || $this->error(403, 'You trying use a unsupported request method.');
            
            $xhr_headers = array('HTTP_XHR_ENGINE', 'HTTP_XHR_FUNCTION', 'HTTP_XHR_ID', 'HTTP_XHR_TOKEN');
            $keys = array_intersect($xhr_headers, array_keys($_SERVER));
//error_log(var_export($_SERVER, true));
            $this->isXHR = count($keys) == count($xhr_headers) && $_SERVER['HTTP_XHR_ENGINE'] == AjaxLoader::ENGINE && isset($_SERVER['HTTP_XHR_FUNCTION']);
           
            if($this->isXHR)
            {
                if($this->method != 'post')
                {
                    error_log('XHR_ERROR: Wrong request method! Possible attack.');
                    die('{"error":"Wrong ajax call method!<br/>Use \'AjaxLoader.call(string $function, string|json $arguments, function $callback)\' instead."}');
                }
                if(!function_exists($_SERVER['HTTP_XHR_FUNCTION']))
                {
                    error_log('XHR_ERROR: Wrong function name! Possible attack.');
                    die('{"error":"Wrong function name!<br/>Use \'AjaxLoader.call(string $function, string|json $arguments, function $callback)\'."}');
                }
                if((function_exists('session_status') && PHP_SESSION_ACTIVE == session_status()) //php >= 5.4.0
                    || isset($_SESSION) && !empty($_SESSION)) //PHP < 5.4.0 
                {               
                    if(!is_string($_SERVER['HTTP_XHR_TOKEN']) || !is_string($_SERVER['HTTP_XHR_ID']))
                    {
                        error_log("XHR_ERROR: Ajax attack from '{$_SERVER["REMOTE_ADDR"]}'.");
                        die('{"error":"Your illegal action will be logged.<br />Request denied!"}');
                    } 
                    list($id, $time) = explode('-', $_SERVER["HTTP_XHR_ID"]);
                    if(AjaxLoader::generateToken($id, $time) != $_SERVER['HTTP_XHR_TOKEN'])
                    {
                        error_log("XHR_ERROR: Ajax attack from '{$_SERVER["REMOTE_ADDR"]}'.");
                        die('{"error":"You are not using \'AjaxLoader.call()\' function.<br />Request rejected!"');
                    } 
                }
                if(!((is_string($this->ajaxFunctions) && strcasecmp($this->ajaxFunctions, $_SERVER["HTTP_XHR_FUNCTION"]) == 0) || 
                    (is_array($this->ajaxFunctions) && in_array(strtolower($_SERVER["HTTP_XHR_FUNCTION"]), array_map('strtolower', $this->ajaxFunctions))) ||
                    is_callable($_SERVER["HTTP_XHR_FUNCTION"])))
                {
                    error_log(true==(is_string($this->ajaxFunctions) && strcasecmp($this->ajaxFunctions, $_SERVER["HTTP_XHR_FUNCTION"]) == 0));
                    error_log(true==(is_array($this->ajaxFunctions) && in_array(strtolower($_SERVER["HTTP_XHR_FUNCTION"]), array_map('strtolower', $this->ajaxFunctions))));
                    error_log(is_callable($_SERVER["HTTP_XHR_FUNCTION"]));
                    error_log("XHR_ERROR: Ajax attack from '{$_SERVER["REMOTE_ADDR"]}'.");
                    die('{"error":"You have no permission to access this function!"}');
                } 
            } else {
                $method = function_exists($method) ? $method : "request";   //função que é chamada por defeito 'request()'
                $fct = create_function('$a', 'if(!function_exists($a)) {new Exception("The method \'all\' is deprecated!"); return \'all\';} return $a;'); 
                if("request" == $method) $method = $fct($method);
                function_exists($method) && is_callable($method) || $this->error(405, "The application can't process the request."); //erro, caso nao exista função    
            }

            $this->vars =  $this->cleanVar($_REQUEST);
            $this->vars['files'] = $this->cleanVar($_FILES);
            $this->vars['cookie'] = $this->cleanVar($_COOKIE);
            if((function_exists('session_status') && PHP_SESSION_ACTIVE == session_status()) //php >= 5.4.0
                || isset($_SESSION) && !empty($_SESSION)) //PHP < 5.4.0
                $this->vars['session'] = $this->cleanVar($_SESSION);

            $this->vars['method'] = $this->method;
            $this->method = $method;
            //$this->vars['document_root'] = $this->document_root = str_replace(DIRECTORY_SEPARATOR, '/', $_SERVER["DOCUMENT_ROOT"]);
            //$this->vars['script_path'] = $this->script_path = rtrim(str_replace('\\', '/', dirname($_SERVER["SCRIPT_FILENAME"])), '/');     
            $this->vars['document_root'] = $this->document_root = $_SERVER["DOCUMENT_ROOT"];
            $this->vars['script_path'] = dirname($_SERVER["DOCUMENT_ROOT"].$_SERVER["PHP_SELF"]);
             
            $this->vars['server'] = array('year'=>"date('Y')", 'month'=>"date('n')", 'day'=>"date('J')", 
                'numberOfDays'=>"date('t')", 'hour'=>"date('H')", 'minute'=>"date('i')", 'second'=>"date('s')", 
                'token'=>'md5(sprintf("%s%s%s%s", $_SERVER["REMOTE_PORT"], memory_get_usage(), uniqid(), $_SERVER["REMOTE_ADDR"]))'); 
        }

        /**
        * Define ficheiro com layout base da página HTML
        * 
        * @param string Caminho relativo ou absoluto para vista principal
        */
        public function setMainView($view) 
        {
            if(!isset($this->mainView))
            { 
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
        public function addViewParts() 
        {
            $args = func_get_args();
            if(count($args) == 1) 
            {
                $views = $args[0];
                $name = '';
            }
            if(count($args) > 1) 
            {
                $views = $args[0];
                $name = $args[1];
            }
          
            switch(gettype($views))
            {
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

        public function warning($warn) 
        {
            $this->debugMsg = "\n{$this->debugMsg}\n$warn";
        }

        public function loadResources($filepath, $section=null) 
        {
            if(!empty($filepath)) 
            {
                $resPath = realpath($filepath); 
                if(is_file($resPath))
                {       
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
        public function dbConnect($path=NULL) 
        {
            $db = new Database;
            if(is_file($path))
            {
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

        private function internalLoadResources($file) 
        {
            $fileLines = array();
            $filepath = '';      
                                                                                                                 
             if(is_file($file))
                $fileLines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);   
                
             if(is_file("resources/$file"))
                $fileLines = file("resources/$file", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); 
             
            $arr = array();   
            if(is_array($fileLines) and count($fileLines) > 0) 
            {                          
                foreach($fileLines as $line) 
                {
                    $item = explode("=", $line, 2);
                    if(count($item) != 2) continue;
                    $arr[trim($item[0])] = htmlentities(trim($item[1]), ENT_NOQUOTES, $this->encoding);
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

                if(count($this->views) > 0) 
                { 
                    foreach($this->views as $view) 
                    {
                        if(is_file($view) && filemtime($view) > $tplModificationTime)                             
                            return true; 
                    }
                }
                if($this->debug) $this->debug("View already created in FS", "PHP Loader");
                return false;
            } else
                return true; //nao existe vista gerada           
        }
           
        /**
        *  generate and execute the page
        */
        public function process() { $this->processPage(); }
        
        /**
        *  generate and execute the page
        * @deprecated
        */
        public function processPage()
        {                                 
            if($this->isXHR)
            {
                $ajaxFn = call_user_func($_SERVER["HTTP_XHR_FUNCTION"], $this);
                die(is_string($ajaxFn) ? $ajaxFn : json_encode($ajaxFn));
            }                                                                                   
            if(count($this->views) == 0) 
            {
                $tpl = pathinfo($_SERVER["SCRIPT_FILENAME"]);
                $tpl = "{$tpl['dirname']}/{$tpl['filename']}.tpl";
                if(is_file($tpl)) $this->views[] = $tpl; //adiciona o template por defeito e continua para processa-lo
                unset($tpl);
            }

            if(!$this->cache || $this->generatePage()) 
            {
                $this->body_content = $this->loadViewPart($this->mainView); // Load mainView
                while(empty($this->body_content))  //retrieve views until last one
                {
                    count($this->views) > 0 || $this->error(404, "There are no views to show.\nIt's impossible return any response! Add at least one view");
                    $this->body_content = $this->loadViewPart(array_shift($this->views));
                }

                $this->manageViews(); //load viewParts  
                $this->body_content = preg_replace( //clean html view
                    array('/<!DOCTYPE[^>]*>/i', "/<\/?html[^>]*>/i", "/<\/?head[^>]*>/i", "/<\/?body[^>]*>/i"/*, '/<!--.+?-->/'*/),
                    "", 
                    $this->body_content);
                                           
                $this->processSet();
                                                                                  
                $this->body_content = $this->processFor($this->body_content, '$this->vars');
                $this->processIfElse();
                $this->body_content = preg_replace("/<\/php:(if|else|for|set)\s*>/i", "<?php } ?>", $this->body_content);

                $this->processVars($this->body_content);                                                        
                                                       
                //replace internal vars     
               //$this->processInternalVars();
                $this->body_content = preg_replace('/\$(\$\w+)/', '$1', $this->body_content); //escape de cifrao

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
            
            //Se houver sessao valida  
            if((function_exists('session_status') && PHP_SESSION_ACTIVE == session_status()) //php >= 5.4.0
                || isset($_SESSION) && !empty($_SESSION)) //PHP < 5.4.0 
            {
                $this->vars['session'] = $this->cleanVar($_SESSION);
                $_SESSION['__token'] = $this->vars['server']['token'];
            }
            $this->writeResponse();
        }
/*
        private function processInternalVars()
        {
            $intVarsCount = preg_match_all('/([^$])(\$__[a-z0-9\.]+)/i', $this->body_content, $internalVar, PREG_OFFSET_CAPTURE);
            if($intVarsCount > 0)
            {
                for($i=$intVarsCount-1; $i>-1; $i--)
                {                                                    //strpos($internalVar[2][$i][0], '.')
                    $stripIntVar = substr($internalVar[2][$i][0], 1);
                    $stripIntVarArr = explode('.', $stripIntVar); 
                    if(array_key_exists($stripIntVarArr[0], $this->vars)) 
                    {
                        $stripIntVar = $this->vars;
                        foreach($stripIntVarArr as $intValue)
                            $stripIntVar = $stripIntVar[$intValue];
                            
                        $this->body_content = substr_replace($this->body_content, 
                            sprintf('<?php echo %1$s; ?>', $stripIntVar),
                            $internalVar[2][$i][1], 
                            strlen($internalVar[2][$i][0])
                        ); 
                    }
                }
            }
        }      
*/
        /**
        * Substitui as tags <php:set
        *         
        */
        private function processSet()
        {  
            $count = preg_match_all('/<php:set\s+([^\>]+)*>\s*(.*?)\s*<\/php:set>/si', $this->body_content, $set, PREG_OFFSET_CAPTURE);
            if($count > 0) 
            {      
                for( ;$count > 0; $count--) 
                {
                    $replace = "";
                    $it = 1; 
                    $setVar = $this->processVar(rtrim(ltrim($set[1][$count-1][0], '$'))); 
                    $setValue = $set[2][$count-1][0];  
                   
                    if(startsWith("<php:", $setValue) and endsWith(">", $setValue)) 
                    {  
                        if(startsWith("<php:resources", $setValue)) 
                        {
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
                }                                                                                       
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
            if($count > 0) 
            {       
                $dynamic = $dynamic[0];  
                for($i = $count-1; $i >= 0; $i--) 
                {
                    $this->body_content = substr_replace($this->body_content, "", $dynamic[$i][1], strlen($dynamic[$i][0]));
                    $dynamic_html = "{$dynamic[$i][0]}$dynamic_html";
                }                                         
            }           
            $this->headers['dynamic'] = $dynamic_html;

            $count = preg_match_all('/<title.*?>.*?<\/title>/si', $this->body_content, $title, PREG_OFFSET_CAPTURE);
            if($count > 0) 
            {
                $title = $title[0];
                for($i = $count-1; $i > -1; $i--) 
                    $this->body_content = substr_replace($this->body_content, "", $title[$i][1], strlen($title[$i][0]));
            }

            if(!empty($this->title))
                $this->head_content = "{$this->head_content}<title>{$this->title}</title>"; //adiciona titulo
            else if($count > 0)
                $this->head_content = "{$this->head_content}{$title[$count-1][0]}"; 
            
                $count = preg_match_all('/<(meta|link).*?[^-\?]>/si', $this->body_content, $meta, PREG_OFFSET_CAPTURE);
            if($count > 0) 
            {
                $meta = $meta[0];
                for($i = $count-1; $i >= 0; $i--) 
                {
                    $this->body_content = substr_replace($this->body_content, "", $meta[$i][1], strlen($meta[$i][0]));
                    $this->head_content = "{$meta[$i][0]}{$this->head_content}";
                }
            }
            
            $count = preg_match_all('/<style.*?>(.*?)<\/style>/si', $this->body_content, $style, PREG_OFFSET_CAPTURE);
            if($count > 0) 
            {           
                for($i = $count-1; $i > -1; $i--) 
                {
                    $this->body_content = substr_replace($this->body_content, "", $style[0][$i][1], strlen($style[0][$i][0]));
                    $this->headers['style'] = "{$style[1][$i][0]}{$this->headers['style']}"; 
                }
                $this->headers['style'] = preg_replace(array('/\s*\r\n\s*/',  '/\s*\/\*.*?\*\/\s*/'), '', $this->headers['style']); //remove comentarios
            }

            $count = preg_match_all('/<script(.*?[^-\?])\/?>(?:(.*?)<\/script>)?/si', $this->body_content, $script, PREG_OFFSET_CAPTURE);
            if($count > 0) 
            {
                //$script = $script[0];
                for($i = $count-1; $i >= 0; $i--) 
                {
                    $phpCount = preg_match_all('/<\?php\s+[^?]+\?>/i', $script[1][$i][0], $encScript);
                    if($phpCount > 0)
                        $script[1][$i][0] = str_replace($encScript[0], '%s', $script[1][$i][0]);

                    if(preg_match('/(?:src|href)=([\'"])(.*?)\1/si', $script[1][$i][0], $src))
                    {                             
                        if(!preg_match("/^(ht|f)tps?:\/\//", $src[2]) && !in_array($src[2][0], array('/', '%'))) 
                        {
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

            if(isset($this->icon)) 
            {
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
        private function generateMetaHeaders()
        {
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
            if(empty($file)) 
            {
                if(is_file($this->getCacheFilename()) && is_readable($this->getCacheFilename()))
                include($this->getCacheFilename());
            } else {                    
                include($file);  //output the php script        
            }
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

            while(preg_match("/<php:(if|else)([^>]*)>/i", $this->body_content, $ifelse)) 
            { 
                $ifelse[2] = trim($ifelse[2], " \/");
                $ifelse[2] = preg_replace($replaces_keys, $replaces_values, $ifelse[2]);

                if(!empty($ifelse[2])) {
                    $vars = $this->extractVars($ifelse[2]);

                    $ifTestCode = "";
                    if(count($vars)>0) {
                        foreach($vars as $i=>$var) 
                        {
                            $varClass = $this->processVar($var);
                            $ifelse[2] = substr_replace($ifelse[2], $varClass, $i, strlen($var)+1);
                            $vars[$i] = $varClass;
                        }
                        $u_vars = array_unique($vars);

                        foreach($u_vars as $u_var) 
                        {
                            $arrayInit = 0;
                            $test_vars = "";
                            while(($arrayPos = strpos($u_var, '][', $arrayInit)) != false) 
                            {
                                $arrayVar = substr($u_var, 0, $arrayPos+1);
                                $test_vars = sprintf('%1$s if(!is_array(%2$s) && !is_scalar(%2$s)) %2$s = array();', $test_vars, $arrayVar);
                                $arrayInit = $arrayPos + 2;                              
                            }
                            $test_vars = strcmp($u_var[0], '$') == 0 ? sprintf('%1$s if(!isset(%2$s)) %2$s = null;', $test_vars, $u_var) : '';

                            if(!startsWith('$__', $u_var)) 
                            {
                                if(!empty($test_vars) && !in_array($test_vars, $testVarsCode))
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
        { 
            //"/([^\\\$])(\\\$([a-z0-9_\.\?]+))(|[a-z_]+(:[a-z0-9_\"'])?)?(}?)/i"
            $count = preg_match_all('/(([^\$])\s*)\$([a-z0-9\?]{1,2}[a-z0-9_\.]*)(?:\s*\|\s*([^\}]+))?(\})?/i',$html, $var, PREG_OFFSET_CAPTURE);
            
            $entries = array();      
            if($count > 0){
                $functions = array(
                    'upper' => 'strtoupper',
                    'lower' => 'strtolower',
                    'title' => 'ucwords',
                    'text' => 'html_entity_decode',
                    'html' => 'htmlspecialchars',
                    'base64' => 'base64_encode' 
                );

                foreach($this->vars['server'] as $k => $v)
                    $entries["server.$k"] = $v;
                
                for($i=$count-1; $i>=0; $i--) {
                    if($var[3][$i][0]=="this") 
                        continue;

                    if($var[2][$i][0]=="{" && is_array($var[5][$i]) && $var[5][$i][0]=="}") $var[1][$i][0]="";
                    if(array_key_exists($var[3][$i][0], $entries))
                    {
                        $replace = $entries[$var[3][$i][0]];
                    } else {
                        $replace = $this->processVar($var[3][$i][0], true);
                        $entries[$var[3][$i][0]] = $replace;
                    }
                    /*
                    if(empty($var[4][$i][0]) || !array_key_exists(trim($var[4][$i][0]), $functions))
                        $replace = "{$var[1][$i][0]}<?php if(isset($replace) && is_scalar($replace)) echo $replace; ?>";
                    else 
                    */
                    $replaceExp = startsWith('server.', $var[3][$i][0]) ? '%1$s<?php echo %3$s(%2$s); ?>' : '%1$s<?php if(isset(%2$s) && is_scalar(%2$s)) echo %3$s(%2$s); ?>';
                    $replace = sprintf($replaceExp, 
                        $var[1][$i][0], 
                        $replace, 
                        empty($var[4][$i][0]) || !array_key_exists(trim($var[4][$i][0]), $functions) ? '' : $functions[trim($var[4][$i][0])]
                    );    

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
            if(strcasecmp($params[0], 'server') == 0){
                $value = $this->vars;
                foreach($params as $param)
                    $value = is_array($value) && array_key_exists($param, $value) ? $value[$param] : '';
                return $value;
            } else {
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
                $varCode = sprintf($replace, $params[0], $arrVars);
            }
          /*  if($params[0] == 'server')
            {            
                create_function('$arr', 'return $a')
            }      */   
            return $varCode;           
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

                $content = sprintf("<!-- %s -->\r\n<!DOCTYPE html>\r\n<html>\r\n<head>\r\n%s\r\n%s\r\n%s\r\n%s\r\n</head>\r\n<body>\r\n%s\r\n%s\r\n%s%s\r\n<script type='text/javascript'>%s</script></body>\r\n</html>",  
                    $this->debug ? $_SERVER['PHP_SELF'] : "Generated by {$this->name}/{$this->version}",  
                    $this->generateMetaHeaders(),
                    $this->head_content, 
                    "<style type='text/css'>{$this->headers['style']}</style>",
                    "<?php if(\$this->debug) echo \"$debug_css\"; ?>",                             
                    '<?php if($this->debug) echo $this->debugMsg; ?>', 
                    $this->body_content,
                    implode($this->headers['script']),
                    $this->headers['dynamic'],
                    AjaxLoader::script()
                );      

                /*
                $content = preg_replace(array("/\?>\s+<\?php/", "/\r\n\s+\r\n/"), array(''), $content);
                */
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
                400 => "Bad Request",
                403 => "Forbiden",
                404 => "Not Found",
                405 => "Method Not Allowed",
                500 => "Internal Server Error", 
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
    function ne($var1, $var2) { return $var1!=$var2; }
    function lt($var1, $var2) { return $var1<$var2; }
    function lte($var1, $var2) { return $var1<=$var2; }
    function gt($var1, $var2) { return $var1>$var2; }
    function gte($var1, $var2) { return $var1>=$var2; }
    function between($var, $v1, $v2) {
        if($v1>$v2) return $v2<=$var && $var<=$v1;
        else return $v1<=$var && $var<=$v2;
    }                                        
    function startsWith($substr, $str, $case_sensitive = FALSE)
    {
        if(!(is_bool($case_sensitive) && $case_sensitive)) 
        {
            $substr = strtolower($substr);
            $str = strtolower($str); 
        }
        return substr($str, 0, strlen($substr)) == $substr;
    }
    function endsWith($substr, $str, $case_sensitive = FALSE)
    {
        if(!(is_bool($case_sensitive) && $case_sensitive)) 
        {
            $substr = strtolower($substr);
            $str = strtolower($str); 
        }
        return substr($str, 0-strlen($substr)) == $substr;
    }

    final class Database
    {               
        const ASSOC = "ASSOC";
        const NUM = "NUM";
        const BOTH = "BOTH";

        private $driver;
        private $conn;
        //private $errorLog;
        private $result;
        private $debug = FALSE;
        private $persistentConn = false;

        public static function getClassName() { return get_class(); }                                             
        public function setDebug($debug = true) { $this->debug = $debug; }                                        
        public function usePersistentConnection($persistent = true) { $this->persistentConn = (bool) $persistent; }
        //public function __set($name, $value) { return; } 
        public function __get($name) { return $this->$name; }

        function getConnectionInfo()
        {
            switch($this->driver)
            {
                case 'mysqli':
                    return mysqli_get_connection_stats($this->conn);
                case 'mysql':
                    $retStatus = array();
                    $stats = explode('  ', mysql_stat($this->conn));
                    foreach($stats as $stat) {
                        $rowStat = explode(':', $stat);
                        $retStatus[str_replace(' ', '_', strtolower($rowStat[0]))] = trim($rowStat[1]); 
                    }
                    return $retStatus; 
                case 'interbase':
                    break;
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
        public function MySQL($host="127.0.0.1", $database, $username, $password, $port=3306)
        {
            $this->getDriver("mysql");
            switch($this->driver)
            {
                case "mysqli":
                    if(extension_loaded("mysqli"))
                    {
                        if($this->conn = mysqli_init())
                        {
                            mysqli_options($this->conn, MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 1');
                            mysqli_options($this->conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);

                            if($this->conn){ //is really connected
                                if(!mysqli_real_connect($this->conn, $host, $username, $password, $database, $port)) {
                                    $this->connectError(mysqli_connect_error(), mysqli_connect_errno());
                                }
                                return;
                            } else
                                $this->connectError(mysqli_connect_error(), mysqli_connect_errno());
                            }
                    } else
                        error_log("Extension not loaded: mysqli !\n Trying next extension!");
                case "mysql":
                    if(extension_loaded("mysql"))
                    {
                        $this->driver = "mysql";
                        $this->conn = call_user_func_array($this->persistentConn ? 'mysql_pconnect' : 'mysql_connect', array("$host:$port", $username, $password));
                        if($this->conn) 
                        {
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
        * @param string Encryption key
        */
        public function SQlite($filename, $key=NULL)
        {
            //$this->getDriver("sqlite");var_dump($this->driver);
            //switch($this->driver)
            //{
                //case "sqlite3":
                    if(extension_loaded("sqlite3") && is_file($filename))
                    {
                        $this->conn = new SQLite3($filename, SQLITE3_OPEN_READWRITE, $key);  
                        $errorCode = $this->conn->lastErrorCode(); 
                        //if($errorCode == 0) break; // No error 
                        if($errorCode == 0) return; // No error 
                        //$this->connectError($this->conn->lastErrorMsg(), $errorCode);
                    }
                //case "sqlite":
                    if(extension_loaded("sqlite") && is_file($filename))
                    {
                        if(($this->conn = sqlite_open($filename, 0666, $error)) == FALSE)
                            $this->connectError($error);
                        else
                            //break;
                            return;
                    }
                //default:
                    $this->errorDie("SQlite Error: Can't connect to database!");
            //}
        }

        /**
        * Open connection with Firebird Database Server
        * 
        * @param string Hostname or IP Address
        * @param string DB alias or DB filepath
        * @param strin DB Username
        * @param string DB password
        */
        public function Firebird($host="127.0.0.1", $database, $username, $password){ $this->InterBase($host, $database, $username, $password); }

        /**
        * Open connection with InterBase Database Server
        * 
        * @param string Hostname or IP Address
        * @param string DB alias or DB filepath
        * @param strin DB Username
        * @param string DB password
        */
        public function InterBase($host="127.0.0.1", $database, $username, $password)
        { 
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
                    return false;
                case 'mysql': 
                    if(mysql_num_rows($this->result) == 0)
                        return mysql_data_seek($this->result, 0);
                    return false;
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
            if(!is_string($sql) || empty($sql))  
            {
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
            switch($this->driver)
            {
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
            if($this->result == false) return array();

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
            if($this->result == false) return array();
                               
            $it=0;
            $results = array();
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
                                           
        private function getDriver($engine, $version=NULL)
        {
            $db = $this->supportedDBs(strtolower($engine));
            switch(gettype($db))
            {
                case "string":
                    $this->driver = $this->loadExtension($db) ? $db : null;
                    break;

                case "array":
                    foreach($db as $e)
                    {
                        if($this->loadExtension($e))
                        {
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
        private function queryError()
        {
            $msg = "Query to BD has failed!";

            switch($this->driver)
            {
                case 'mysqli': 
                    $msg = sprintf("%s\n%d: %s", $msg, mysqli_errno($this->conn), mysqli_error($this->conn));
                    break;

                default: 
                    $msg = "$msg\n No driver to connect DB and execute querys.";
            }
            error_log($msg);
        }
                                        
        private function errorDie($msg='')
        {
            error_log($msg);
            die($msg);
        }

        private function loadExtensionError($drvName=null) 
        {
            if(isset($drvName))   
                error_log("Error loading driver: $drvName");
            die("ERROR: Connecting BD");
        }
        
        private function connectError($msg, $code='')
        {
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

        function __destruct ()
        {
            if($this->conn != null && !$this->persistentConn) 
            {
                switch($this->driver)
                {
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

        public static function supportedDBs($name='')
        {
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

        private static function loadExtension($extName)
        {
            if(!extension_loaded($extName) && strtolower(ini_get("enable_dl")) == "on") 
                dl(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? "php_$extName.dll" : "$extName.so");
                
            return extension_loaded($extName);
        }
    }                        
    
    define('id', 'primary_key', true);
    define('column', 'name', true);
    define('is_null', 'empty', true);      
    define('type', 'gettype', true);    
    define('length', 'strlen', true);
    define('size', 'strlen', true);
    define('value', 'value', true);    
         
    function is_number($value) { return is_int($value) ? true : is_numeric($value) && strval(intval($value)) == $value; }
             
    abstract class Entity {                
        public static $connetion = null; 
        private static $types = array(
            'is_string'=>array('varchar', 'varchar2', 'text', 'string'),
            'is_number'   =>array('numeric', 'int', 'integer', 'number')
        );      
        public static function __callStatic ($name , $arguments)
        {                             
            switch(strtolower($name)) {
                case 'columns' : return is_object($arguments[0]) && is_a($arguments[0], get_class(), false) ? array_keys($arguments[0]) : array(); 
                case 'connection' : self::$connetion = $arguments[0];
            }
        }                                            
        
        private $localConnetion = null; 
        private $bdname = null;
        private $columns = array();    
        private $values = array();            
        private $relations = array();         
        private $orderBy = array();   
        private $settings = array();  
        private $error = null;     
        private $sqlCode = '';                                          
        
        protected final function reportError($field, $constraint, $desc) { $this->error = array('field' => $field, 'constraint' => $constraint, 'desc' => $desc); } 
        public final function lastError(){ return $this->$error; }  
        public final function toArray() { return $this->values; }    
        public final function getCode() { return $this->sqlCode; }                                
        
        public final function __construct() 
        {             
            $this->bdname = get_class($this);      
            $columns = array_diff_key(get_class_vars($this->bdname), get_class_vars(get_class()));
            
            foreach($columns as $var => $column) 
            {                
                switch(gettype($column))
                {           
                    case 'string':   
                        if(!empty($column))  
                            $this->columns[$var] = $column;      
                        break;
                    case 'array':
                        if(array_key_exists(column, $column) && is_string($column[column]) && !empty($column[column])) 
                        {
                            $this->columns[$var] = $column[column];    
                            
                            if(array_key_exists(type, $column))
                            {
                                foreach(self::$types as $typeKey => $typeList)  
                                    if(in_array($column[type], $typeList)) 
                                        $column[type] = $typeKey; 
                            }
                            if(is_array($column))
                                $this->settings[$var] = $column; 
                        }
                        break;
                }
                unset($this->$var);                                                        
            }

            if(defined("{$this->bdname}::NAME"))
                $this->bdname = constant("{$this->bdname}::NAME");   
            if(defined("{$this->bdname}::name"))
                $this->bdname = constant("{$this->bdname}::name"); 
                
            $this->setConnection(Entity::$connetion);
        } 
        
        public final function __set($name, $value)
        {   
            if(array_key_exists($name, $this->columns))                   
                $this->values[$this->columns[$name]] = $value;                                                              
        }

        public final function __get($name)
        {
            if(array_key_exists($name, $this->columns) && array_key_exists($this->columns[$name], $this->values))
                return $this->values[$this->columns[$name]];
            else
                return null;
        }       
           
        private function validateAndGet()
        {
            $values = array();
            
            foreach($this->columns as $key => $column) 
            {
                if(array_key_exists($key, $this->settings))
                {
                    if(array_key_exists(id, $this->settings[$key]) && $this->settings[$key][id] == true) continue; 
                    
                    foreach($this->settings[$key] as $propKey => $propValue)
                    {
                        switch($propKey)
                        {                  
                            case is_null:  
                            case type:  
                            case size:  
                            case length: 
                                $fnValue = call_user_func($propKey, $this->values[$column]);
                                $propValue = $this->settings[$key][$propKey];
                                                                       
                                if((is_numeric($propValue) && intval($propValue) >= $fnValue) || 
                                    (is_array($propValue) && count($propValue) == 2 && between($fnValue, $propValue[0], $propValue[1]))) 
                                {
                                    $values[$column] = $this->values[$column];
                                    continue;
                                }
                                
                                $this->reportError($key, $propKey, "Validating field '$key' for '$propKey' function!");
                                error_log("ERROR: ".$this->error['desc']);  
                                return false;  
                        }
                    }   
                } else { 
                    $values[$column] = $this->values[$column];
                }   
            }
            return $values;                                                                
        }
       
        public final function update() 
        {
            $columns = $this->validateAndGet(); 
            $ids = array_diff(array_values($this->columns), array_keys($columns));
            
            $idsVal = array();
            foreach($ids as $key) 
            {
                if(isset($this->values[$key]))        
                    $idsVal[] = sprintf("%s='%s'", $key, $this->values[$key]); 
            }
            if(empty($idsVal)) 
            {     
                $this->reportError(implode(',', $ids), 'id', 'Update operation require a valid ID!');
                error_log("ERROR: ".$this->error['desc']);  
                return false;  
            }         
            
            $vals = array();
            foreach($columns as $key => $val)
                $vals[] = "$key='$val'"; 
            
            if(is_array($columns))
            {   
                $this->sqlCode = sprintf('update %s set %s where %s',
                    $this->bdname, 
                    implode(',', $vals),
                    implode("','", $idsVal)                    
                    );
                    return $this->localConnetion->execute($this->sqlCode); 
            }
        }            
        
        public final function insert()
        {                  
            $columns = $this->validateAndGet();
            if(is_array($columns))
            {       
                $this->sqlCode = sprintf("insert into %s(%s)values('%s')",
                    $this->bdname,
                    implode(',', array_keys($columns)),
                    implode("','", array_values($columns))
                );                            
                return $this->localConnetion->execute($this->sqlCode);  
            }
        }                                                         
        
        /**
        * Perfom a select operation                
        * @param mixed columns name
        * @return Number of rows in result set
        */
        public final function select() 
        {                         
            $filter = array();
            foreach($this->values as $key => $val)
                $filter[] = sprintf("%s %s '%s'", $key, startsWith('%', $val) || endsWith('%', $val) ? 'like' : '=', str_replace("'", "''", $val)); 
                
            $this->sqlCode = sprintf('select %s from %s %s', 
                implode(',', $this->columns), 
                $this->bdname,                                                           
                empty($filter) ? '' : sprintf('where %s', implode(' and ', $filter))      
            );
            unset($filter);
                                            
            if($this->localConnetion != null && $this->localConnetion->conn != null) 
            {
                $this->operation = 'select';              echo "QUERY: ", $this->sqlCode, '<br />';
                return $this->localConnetion->query($this->sqlCode, Database::ASSOC);  
            } else {
                throw new Exception('The database connection is not valid!', 500);
            }
        }                                                        
        
        protected function addRelation($localColumn, $remoteColumn) 
        {
            if(in_array($localColumn, $this->columns))
                $this->relations[$localColumn] = $remoteColumn;            
        }   

        public final function columns() 
        {
            switch(func_num_args()) 
            {
                case 0:
                    return $this->columns;
                case 1:
                    $cols = func_get_arg(0);

                    if(array_key_exists($cols, $this->columns))  
                        return $this->columns[$cols];          
                        
                    return $this->columns;
                default:
                    $cols = func_get_args();
            }
            
            $retCols = array();
            foreach($cols as $col)
            {
                if(is_scalar($col) && array_key_exists($col, $this->columns))
                    $retCols[] = $this->columns[$col];
            }
            return $retCols;
        }

        public final function next() 
        {                         
            if($this->localConnetion != null && $this->localConnetion->conn != null && $this->operation == 'select') 
            {
                $this->values = $this->localConnetion->fetch();
                return true;
            }
            return false;
        }
        
        public final function toString()
        {
            $string = array();
            foreach($this->columns as $key) 
                $string[$key] = array_key_exists($key, $this->values) ? $this->values[$key] : null;
            
            return json_encode($string);             
        }
    
        /**
        * Set connection for this entity
        * 
        * @param Database instance
        */   
        public final function setConnection($conn) 
        {      
            if(is_a($conn, Database::getClassName())) 
            {
                $this->localConnetion = $conn;
                $this->error = '';
            } else {
                error_log("Wrong database connection. Required Database instance");
                $this->error = 'Invalid database connection.';
            }
        }          
    }   
    
    class AjaxLoader{
        const ENGINE = 'AjaxLoader/1.0';
        
        public static function generateToken($id, $time) {
            $ajaxToken = md5(sprintf('%s%s%s%s', $time, $_SERVER['PHP_SELF'] , $id, $_SERVER['REMOTE_ADDR']));
            $_SESSION['__xhr_token'] = $ajaxToken; 
            return $ajaxToken;
        }
         
        public static function generateId() {
            $ajaxId = sprintf('%s%s', $_SERVER['REMOTE_PORT'], intval(uniqid())); 
            $_SESSION['__xhr_id'] = $ajaxId; 
            return $ajaxId;
        }
        
        public static function script()
        {
            return sprintf('<?php $_SESSION["__xhr_time"] = $_SERVER["REQUEST_TIME"]; $ajaxId = AjaxLoader::generateId(); ?>var AjaxLoader=(function(){var req={},id="<?php echo "$ajaxId-{$_SERVER["REQUEST_TIME"]}"; ?>",token="<?php echo AjaxLoader::generateToken($ajaxId, $_SERVER["REQUEST_TIME"]); ?>",engine="<?php echo AjaxLoader::ENGINE; ?>",make=function(){var xhr;if(window.ActiveXObject){try{xhr=new ActiveXObject("Msxml2.XMLHTTP");}catch(e){try{xhr=new ActiveXObject("Microsoft.XMLHTTP");}catch(e){return false;}}}else if(window.XMLHttpRequest)xhr=new XMLHttpRequest;return xhr?xhr:false;};return{ID:id,TOKEN:token,ENGINE:engine,call:function($name,$args,$fn){if(typeof $name!="string"||$name.length==0)return false; if(typeof(req[$name])!="undefined")this.abort(req[$name]);req[$name]=make();req[$name].open("POST","%s",true);req[$name].setRequestHeader("Content-Type", "application/x-www-form-urlencoded");req[$name].setRequestHeader("XHR_ENGINE",engine);req[$name].setRequestHeader("XHR_TOKEN",token);req[$name].setRequestHeader("XHR_ID",id);req[$name].setRequestHeader("XHR_FUNCTION",$name);if(typeof($args)=="function")$fn=$args;if(typeof($args)=="object"&&String({})==String($args)){var $argsTmp=[];for(var $key in $args)$argsTmp.push($key+"="+$args[$key]);$args=$argsTmp.join("&");}if(typeof($args)!="string")$args=null;if(typeof($fn)=="function")req[$name].onreadystatechange=function(){if(this.readyState==4){$fn.call(this);req[$name]=undefined;}};req[$name].send($args);}, abort:function($name){if(typeof(req[$name])!="undefined"&&[2,3].indexOf(req[$name].readyState)!=-1)req[$name].abort();}}})()',
            $_SERVER['PHP_SELF']);         
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
    * @return array  or false
    */
    function ibase_fetch_array($result, $result_type=IBASE_BOTH)
    {
        if($result) 
        {                                         
            switch($result_type)
            {
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
        return false;   
    }
?>