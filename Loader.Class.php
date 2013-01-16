<?php
/*
$_SERVER['Accept-Encoding'];
ini_set('zlib.output_compression', 'on');
ini_set( 'zlib.output_compression_level', '5' );
*/

ini_get("date.timezone") || ini_set("date.timezone", "Europe/London");
ini_set("display_errors", "on");
//error_reporting(E_ERROR); //production
error_reporting(E_ALL); //development
ini_set('log_error', "LoaderPHP.log");
//ini_set("error_prepend_string", "\n{$_SERVER["SCRIPT_FILENAME"]}: ");

class Loader
{
    private $name = "Loader Framework";
    private $version = "0.1.1";

    private $params;
    private $document_path;
    private $method;
    private $masterPage;
    private $use_cache = true; //save to file
    private $debug = false; //show comments
    private $minimizeHTML = false; //minimize HTML
    private $debugMsg = "";
    private $body_content = "";
    private $head_content = "";
    private $vars = array();
    private $tpl = array();
    private $headers = array(
        'script' => array(),
        'link' => array(),
        'style' => array(),
        'meta' => array());

    public $__cacheDir = "LoaderFramework_cache";
    private $icon = "/favicon.ico";
    private $title;
    // private $var;

    public function __set($name, $value)
    {
        $this->vars[$name] = $value;
    }
    public function __get($name = "")
    {
        return $name != "" && array_key_exists($name, $this->vars) ? $this->vars[$name] :
            "";
    }
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }
    public function useCache($bool)
    {
        $this->use_cache = $bool;
    }
    public function minimize($bool)
    {
        $this->minimizeHTML = $bool;
    }
    public function setTitle($title)
    {
        $this->title = $title;
    }
    public function setIcon($icon)
    {
        $this->icon = $icon;
    }

    /**
     * Define ficheiro com layout base da página HTML
     * 
     * @param string
     */
    public function setMasterPage($masterPage)
    {
        if (!isset($this->masterPage)) {
            if (!is_string($masterPage))
                $this->error(500, "MasterPage must be a string.\n $loader->setMasterPage(string pathname);");
            if (!file_exists(realpath($masterPage)))
                $this->error(404, "MasterPage file doesn't exist.\nPlease referer a valid file.");
            $this->masterPage = $masterPage;
        } else
            if (is_string($masterPage) && file_exists(realpath($masterPage)))
                $this->masterPage = $masterPage;
    }

    /**
     * Adiciona ficheiros com partes da página HTML
     * 
     * @param mixed
     */
    public function addTemplates($tpl)
    {
        switch (gettype($tpl)) {
            case 'array':
                if (count($this->tpl) == 0)
                    $this->tpl = $tpl;
                else
                    $this->tpl = array_merge($this->tpl, $tpl);
                break;
            case 'string':
                $this->tpl[] = $tpl;
                break;
            case 'NULL':
                $this->tpl = null; //desativa uso de templates
            default:
                if ($this->debug)
                    $this->warning('Wrong template name');
                break;
        }
    }

    public function __construct()
    {
        $this->document_path = dirname($_SERVER["SCRIPT_FILENAME"]);

        $method = strtolower($_SERVER["REQUEST_METHOD"]); //metodo HTTP usado
        in_array($method, array("get", "post")) || $this->error(400,
            "You trying use a suspecious method of request.");
        $fn = function_exists($method) ? $method : "all"; //função que é chamada por defeito 'all()'
        function_exists($fn) && is_callable($fn) || $this->error(405,
            "The application can't process the request."); //erro, caso nao exista função

        foreach (array_keys($_REQUEST) as $key)
            $this->vars[$key] = $_REQUEST[$key];
    }

    /**
     * with the gathering data and views, try generate a page   
     */
    public function processPage()
    {
        $debug = $this->processRequest(); //processa o modelo; obtem as variaveis
        if ($this->debug)
            $this->debug($debug); //faz debug ao modelo

        if (is_file($this->getCacheFilename())) { //check if cache file exists
            //if($this->debug) $this->debug("View already created in FS", "PHP Loader");
            //return $this->writeResponse();  //output the response
        }
        /**** Load Templates ****/
        $template_content = "";
        if (is_array($this->tpl))
            switch (count($this->tpl)) {
                case 0:
                    $tpl = pathinfo($_SERVER["SCRIPT_FILENAME"]);
                    $tpl = "{$tpl['dirname']}/{$tpl['filename']}.tpl";
                    if (is_file($tpl))
                        $this->tpl[] = $tpl; //adiciona o template por defeito e continua para processa-lo
                    else
                        break;
                case 1:
                    $this->tpl = $this->tpl[0];
                    if (is_string($this->tpl) && is_file(realpath($this->tpl)))
                        //se existir template

                        $template_content = trim(file_get_contents(realpath($this->tpl))); //lÃª conteudo do template
                    break;
                default:
                    if (is_array($this->tpl) && count($this->tpl) > 0) {
                        //foreach()
                    }
            }
        /**** End ****/

        $this->loadMasterPage($template_content); //load master page

        $this->processHeaders();
        $this->processVars();
        $this->body_content = preg_replace( //clean html view
            array(
            "/<!DOCTYPE[^\>]*>/i",
            "/<\/?html[^\>]*>/i",
            "/<\/?head[^\>]*>/i",
            "/<\/?body[^\>]*>/i",
            "/<!--.*-->/",
            "/\/\*.*\*\//"), "", $this->body_content);
        if ($this->use_cache)
            $this->cacheFile();
        //$this->source($this->body_content);
        $this->writeResponse();
    }

    private function loadTemplates()
    {
        $template_content = "";
        if (is_array($this->tpl))
            switch (count($this->tpl)) {
                case 0:
                    $tpl = pathinfo($_SERVER["SCRIPT_FILENAME"]);
                    $tpl = "{$tpl['dirname']}/{$tpl['filename']}.tpl";
                    if (is_file($tpl))
                        $this->tpl[] = $tpl; //adiciona o template por defeito e continua para processa-lo
                    else
                        break;
                case 1:
                    $this->tpl = $this->tpl[0];
                    if (is_string($this->tpl) && is_file(realpath($this->tpl)))
                        //se existir template

                        $template_content = trim(file_get_contents(realpath($this->tpl))); //lÃª conteudo do template
                    break;
                default:
                    if (is_array($this->tpl) && count($this->tpl) > 0) {
                        //foreach()
                    }
            }
        return $template_content;
    }

    private function loadMasterPage($template_content)
    {
        if (is_file(realpath($this->masterPage))) {
            $this->body_content = trim(file_get_contents(realpath($this->masterPage)));

            if (preg_match("/<master[^>]*>/i", $this->body_content))
                $this->body_content = preg_replace("/<master[^>]*>/i", $template_content, $this->
                    body_content);
        } else
            $this->body_content = trim($template_content);
    }

    private function compactHead($cssTetxt)
    {
        /*
        $media = "";                                       
        if(preg_match("/media=['\"](\w+[^'\"])/i", strstr($cssTetxt, '>', true), $t)) $media = " media='{$t[1]}'";
        
        $cssTetxt = preg_replace(array("/<style.*[^>]/i", "/<\/style>/i", "/\r/", "/\n/"), "", $cssTetxt); //retira tag's    
        $cssTetxt = preg_replace("/\s+/", " ", $cssTetxt); //retira tag's  
        // return "<style type='text/css'$media>$cssTetxt</style>";                                                             */

        return preg_replace(array("/\s{2,}/", "/\n/"), array(" ", ""), $cssTetxt);
    }

    private function processHeaders()
    {
        $this->head_content = "{$this->head_content}\n<meta http-equiv='Content-Type' content='text/html; charset=utf-8' />"; //text encoding
        $this->head_content = "{$this->head_content}\n<meta http-equiv='X-UA-Compatible' content='IE=edge,chrome=1' />"; //IE9 compatibility
        $this->head_content = "{$this->head_content}\n<meta name='generator' content='Loader PHP Class API' />"; // PHP Framework

        if (isset($this->title)) {
            while ($this->cleanText("<title>", "</title>"))
                ; //apaga todos titulos
            $this->head_content = "{$this->head_content}\n<title>{$this->title}</title>"; //adiciona titulo
        } else {
            while (($head = $this->getAndCleanText("<title>", "</title>")) !== false) {
                $this->head_content = "{$this->head_content}$head";
            }
        }
        while (($head = $this->getAndCleanText("<link", ">")) !== false) {
            if (!(isset($this->icon) && preg_match("/rel=[\"']shortcut icon[\"']/i", $head)))
                $this->head_content = "{$this->head_content}$head";
        }
        while (($head = $this->getAndCleanText("<meta", ">")) !== false) {
            $this->head_content = "{$this->head_content}$head";
        }
        while (($head = $this->getAndCleanText("<style", "</style>")) !== false) {
            $head = preg_replace("/\s{2,}/", " ", $head);
            $this->head_content = "{$this->head_content}$head";
        }
        while (($head = $this->getAndCleanText("<script", "</script>")) !== false) {
            $head = preg_replace(array("/\/\*.+?\*\/|\/\/.*(?=[\n\r])/" /*, "/[\r\n]/"*/ ),
                "", $head);
            $this->head_content = "{$this->head_content}$head";
        }
        if (isset($this->icon)) {
            $pathIcon = $this->icon[0] == '/' ? $_SERVER["DOCUMENT_ROOT"] : dirname($_SERVER["SCRIPT_FILENAME"]);
            $pathIcon = "$pathIcon/{$this->icon}";
            if (is_file($pathIcon))
                $this->head_content = "{$this->head_content}\n<link rel='shortcut icon' href='{$this->icon}'/>";
        }
    }

    private function writeResponse($file = "")
    {
        if (ini_get('zlib.output_compression')) {
            if (ini_get('zlib.output_compression_level') != 5)
                ini_set('zlib.output_compression_level', '5');

            ob_start();
        } else
            in_array('HTTP_ACCEPT_ENCODING', $_SERVER) && strstr($_SERVER['HTTP_ACCEPT_ENCODING'],
                "gzip") ? ob_start("ob_gzhandler") : ob_start();

        include empty($file) ? $this->getCacheFilename() : $file; //output the php script
        ob_end_flush();
    }

    /***
    * Procura texto entre 2 strings, devolve o texto encontrado e apaga-o
    * 
    * @param string 
    * @param string 
    * @return string
    */
    private function getAndCleanText($initStr, $endStr, $cleanTags = false)
    {
        if (is_string($initStr) && is_string($endStr) && strlen($initStr) > 0 && strlen
            ($endStr) > 0 && ($posI = stripos($this->body_content, $initStr)) !== false) {
            $posE = stripos($this->body_content, $endStr, $posI);
            if ($posE > $posI) {
                $retStr = substr($this->body_content, $posI, $posE - $posI + strlen($endStr));
                $this->body_content = substr_replace($this->body_content, "", $posI, $posE - $posI +
                    strlen($endStr));
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
        if (is_int($initStr) && is_int($endStr) && $endStr > $initStr) {
            $this->body_content = substr_replace($this->body_content, "", $initStr, $endStr);
            return true;
        }

        if (is_string($initStr) && is_string($endStr) && strlen($initStr) > 0 && strlen
            ($endStr) > 0 && ($posI = stripos($this->body_content, $initStr)) !== false) {
            $posE = stripos($this->body_content, $endStr, $posI);
            if ($posE > $posI) {
                $this->body_content = substr_replace($this->body_content, "", $posI, $posE - $posI +
                    strlen($endStr));
                return true;
            }
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
        if (is_int($initStr) && is_int($endStr) && $endStr > $initStr)
            return substr($this->body_content, $initStr, $endStr);

        if (is_string($initStr) && is_string($endStr) && strlen($initStr) > 0 && strlen
            ($endStr) > 0 && ($posI = stripos($this->body_content, $initStr)) !== false) {
            $posE = stripos($this->body_content, $endStr, $posI);
            if ($posE > $posI)
                return substr($this->body_content, $posI, $posE - $posI + strlen($endStr));
        }
        return false;
    }

    private function processVars()
    {
        $this->processForeach();

        foreach ($this->vars as $key => $val) {
            if (empty($val))
                $var = "";
            if (is_scalar($val))
                $this->body_content = preg_replace("/([^\\\$])(\\\$$key)([^a-zA-Z0-9_])/", "\\1<?php if(array_key_exists('$key', \$this->vars) && is_scalar(\$this->vars['$key'])) echo \\\$this->vars['$key']; ?>\\3",
                    $this->body_content);
        }
    }

    private function processIf()
    {
        while (($posI = stripos($this->body_content, "<if", $posSearch)) !== false) {
            if ($posSearch == $posI) {
                $posSearch += 8;
                continue;
            } //deteta looping na procura do foreach
            $posSearch = $posI; //guarda posiÃ§ao de procura
            if (($posE = stripos($this->body_content, "</if>", $posI)) === false)
                continue;
        }
    }

    private function processForeach()
    {
        $posSearch = 0;
        while (($posI = stripos($this->body_content, "<foreach", $posSearch)) !== false) {
            if ($posSearch == $posI) {
                $posSearch += 8;
                continue;
            } //deteta looping
            $posSearch = $posI; //guarda posiÃ§ao de procura
            if (($posE = stripos($this->body_content, "</foreach>", $posI)) === false)
                continue;

            $foreachAllContent = substr($this->body_content, $posI, $posE - $posI + 10);

            $endAttrPos = strpos($foreachAllContent, ">");
            $foreachAttrs = substr($foreachAllContent, 9, $endAttrPos - 9); //extrai todos atributos
            $foreachAttrs = str_replace(array(
                "\"",
                "'",
                "$"), "", $foreachAttrs); //nome_attr=valor_attr
            $attrsArray = preg_split("/ /", $foreachAttrs, -1, PREG_SPLIT_NO_EMPTY); //separar os atributos, eliminando valores nulos e vazios

            //echo "Dump --> ", var_dump($attrsArray);

            if (count($attrsArray) == 0) {
                if (strlen(trim($foreachAttrs) > 0))
                    $attrsArray = array($foreachAttrs);
                else
                    continue; //se nao houver atributos no <foreach*>...</foreach>
            }

            $local_opt = array(); //inicia o array de atributos
            for ($i = 0; $i < count($attrsArray); $i++) {
                if (empty($attrsArray[$i]))
                    continue;
                list($key, $value) = explode('=', $attrsArray[$i]);

                //$this->source($attrsArray[$i], "var_dump");

                if (!empty($key) && !empty($value))
                    $local_opt[strtolower($key)] = $value;
            }
            //$this->source($local_opt, "\$local_opt");

            if (!array_key_exists('var', $local_opt)) { //se o elemento 'foreach' tem o parametro minimo
                $this->body_content = str_replace($foreachAllContent, "", $this->body_content);
                continue;
            }

            $forContent = substr($foreachAllContent, $endAttrPos + 1, -10);
            /*$rep_else =  preg_match("/<\/?else\/?>(.*)/i", $forContent, $matches) ? $matches[0] : "";*/ //verifica se tem o texto para quando o array é invalido ou nulo

            $split = preg_split("/<\/?else\/?>/i", $forContent, -1, PREG_SPLIT_NO_EMPTY); //separa o com conteudo / sem conteudo

            //$tempKeysName = tmpfile()
            $rep_if = sprintf("<?php if(array_key_exists('%s', \$this->vars) && is_array(\$this->vars['%s']) && (\$count=count(\$this->vars['%s'])) > 0) { \$__%s_keys=array_keys(\$this->vars['%s']); ",
                $local_opt['var'], $local_opt['var'], $local_opt['var'], $local_opt['var'], $local_opt['var']);
            $rep_foreach = sprintf(" for(\$it=%s; \$it<\$count; \$it%s) { ?>",
                array_key_exists('start', $local_opt) && is_numeric($local_opt['start']) ? $local_opt['start'] :
                '0', //posiÃ§Ã£o onde comeÃ§a a percorrer o array
                array_key_exists('step', $local_opt) && is_numeric($local_opt['step']) ? "+={$local_opt['step']}" :
                '++' //incremento
                );
            $local_var = array_key_exists('values', $local_opt) ? $local_opt['values'] : $local_opt['var']; //que variavel substituir

            $preg_replace = $split[0];
            if (array_key_exists('keys', $local_opt)) {
                $preg_replace = preg_replace("/([^\\\$])\\\${$local_opt['keys']}([^a-zA-Z0-9_])/",
                    "\\1<?php echo \$__{$local_opt['var']}_keys[\$it]; ?>\\2", $preg_replace);
            }

            //$this->debug($local_opt['var']);

            if (array_key_exists('values', $local_opt)) {
                //$rep_if = "$rep_if \${$local_opt['values']}=array_values(\$this->vars['{$local_opt['var']}']); ";
                $rep_if = "$rep_if \$__{$local_opt['var']}_values=array_values(\$this->vars['{$local_opt['var']}']); ";
                /*
                $preg_replace = preg_replace(
                "/([^\\\$])\\\${$local_opt['values']}([^a-zA-Z0-9_])/", 
                "\\1<?php echo \$__{$local_opt['var']}_values[\$it]; ?>\\2", 
                $preg_replace
                ); */
            }
            //echo "<textarea>", $preg_replace, "</textarea>";
            if (preg_match_all("/[^\\\$](\\\$$local_var)(\.([a-zA-Z0-9_]+))*(\}?)/", $preg_replace,
                $matches)) {
                //echo "<br>Found: ", count($matches[0]);
                //echo "<fieldset><legend>matches</legend>", htmlentities(print_r($matches, true)), "</fieldset>";

                $match = ($matches[0]);
                unset($matches);
                if (count($match) > 0)
                    for ($it = 0; $it < count($match); $it++) {
                        $items = explode('.', $match[$it]);

                        if (($var0 = array_shift($items)) == null)
                            continue;
                        $var0 = $var0[0] == '{' ? '' : $var0[0]; //se comeÃ§ar por '{', remove
                        $var1 = "";
                        $_count = count($items);
                        if ($_count > 0) {
                            $items[$_count - 1] = rtrim($items[$_count - 1], '}'); //remove '}' do ultimo item
                            for ($var2 = 0; $var2 < $_count; $var2++)
                                $var1 = is_numeric($items[$var2]) ? "{$var1}[{$items[$var2]}]" : "{$var1}['{$items[$var2]}']";
                        }
                        $var_replace = "$var0<?php if(isset(\$this->vars['{$local_opt['var']}'][\$__{$local_opt['var']}_keys[\$it]]$var1)) echo \$this->vars['{$local_opt['var']}'][\$__{$local_opt['var']}_keys[\$it]]$var1; else echo ''; ?>";
                        //echo "<fieldset><legend>local: ", $match[$it],"</legend>", print_r(htmlentities($var_replace), true), "</fieldset>";
                        $preg_replace = str_replace($match[$it], $var_replace, $preg_replace);
                    }
            }

            $rep_foreach = count($split) > 1 ? "$rep_foreach$preg_replace\n<?php } } else { ?>\n{$split[1]}" :
                "$rep_foreach$preg_replace\n<?php } ?>\n";
            $this->body_content = str_replace($foreachAllContent, "$rep_if $rep_foreach\n<?php } ?>\n",
                $this->body_content);
        }
    }

    /**
     * Guarda ficheiro de cache
     * @return boolean
     */
    private function cacheFile()
    {
        $cacheFilename = $this->getCacheFilename();
        $folderCache = dirname($cacheFilename);
        if (is_dir($folderCache) || mkdir($folderCache, 0777, true)) {
            $content = sprintf("<!DOCTYPE html><html><head>%s</head><body>%s%s</body></html>\r\n",
                $this->head_content, substr($this->debugMsg, 6), trim($this->body_content));
            if ($this->minimizeHTML)
                $content = preg_replace("/>\s+</", "><", $content); //retirar espaços entre tags
            return strlen($content) == file_put_contents($cacheFilename, trim($content),
                LOCK_EX); //guarda vista gerada e verifica se escreveu bem o ficheiro
        }
        return false;
    }
    /**
     * Devolve caminho completo para ficheiro de cache
     * @return string
     */
    private function getCacheFilename()
    {
        $folderCache = "{$_SERVER['DOCUMENT_ROOT']}/{$this->__cacheDir}"; //pasta onde guarda os ficheiros de cache
        $cacheFilename = trim(base64_encode($_SERVER["SCRIPT_FILENAME"]), "="); //gera nome unico para cada ficheiro
        //$cacheFilename = trim(base64_encode(hash("crc32", $_SERVER["SCRIPT_FILENAME"], true)), "=");
        return "$folderCache/$cacheFilename.php";
    }

    public function params($name)
    {
        return isset($name) && array_key_exists($name, $this->params) ? $this->params[$name] : false;
    }
    /**
     * Processa o modelo de dados, onde as variaveis sÃ£o criadas
     * @return string
     * @returns coments
     */
    private function processRequest()
    {
        $method = strtolower($_SERVER["REQUEST_METHOD"]);
        $this->params = $_REQUEST;

        if (!function_exists($method))
            $method = "all";
        ob_start();
        call_user_func($method, $this); //chama a função do modelo para processar as variaveis
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
        die(nl2br("<!DOCTYPE html><html><head><meta http-equiv='Content-Type' content='text/html; charset=UTF-8' /><title>$code {$errors[$code]}</title></head><body><center>$msg\n<hr/>Loader Framework v0.1</center></body></html>"));
    }
    private function debug($var, $title = "")
    {
        if (!empty($var))
            $this->debugMsg = sprintf("%s      <fieldset style='border:thin solid blue'>%s<div style='width:100%%; margin-right:2px; max-height: 250px; overflow:auto;'>%s</div></fieldset>",
                $this->debugMsg, empty($title) ? "" : "<legend style='color:blue'>$title</legend>",
                is_array($var) ? var_export($var, true) : $var);
    }
    private function source($var, $title = "")
    {
        if (!empty($var))
            $this->debugMsg = sprintf("%s      <fieldset>%s<textarea style='width:100%%; margin-right:2px; max-height: 250px;' rows='15'>%s</textarea></fieldset>",
                $this->debugMsg, empty($title) ? "" : "<legend>$title</legend>", is_array($var) ?
                var_export($var, true) : $var);
    }
}
?>                        
