<?php

date_default_timezone_set('UTC');
//autoLocale();

$GLOBALS["config"]["DATABASE"] = "data/database.php";
$GLOBALS["config"]["BAYES"] = "data/bayes.php";
$GLOBALS["config"]["LANG"] = "data/lang.php";
$GLOBALS['config']['POSTS_PER_PAGE'] = 20; // Default links per page.
$GLOBALS['config']['CONF_FILE'] = "data/config.php"; // Default links per page.
$GLOBALS['config']['IPBANS_FILENAME'] = 'data/ipbans.php'; // File storage for failures and bans.
$GLOBALS['config']['BAN_AFTER'] = 4;        // Ban IP after this many failures.
$GLOBALS['config']['BAN_DURATION'] = 1800;  // Ban duration for IP address after login failures (in seconds) (1800 sec. = 30 minutes)
$GLOBALS['config']['LOCALE'] = "fr";  // Ban duration for IP address after login failures (in seconds) (1800 sec. = 30 minutes)

$GLOBALS['traduction']['date']['fr'] = array('Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobtre','novembre','décembre');
$GLOBALS["mime"] = array(
    //images
    "png" => "image/png",
    "jpg" => "image/jpg",
    "tiff" => "image/tiff",
    "gif" => "image/gif",
    "svg" => "image/svg+xml",
    "bmp" => "image/bmp",

    //text
    "txt" => "text/plain",

    //audio
    "mp3" => "audio/mpeg",

    //nothin
    "" => "",
);

$GLOBALS['config']['display'] = array(
    "blog" => array("blog", "all"), 
    "galerie" => array("wallpaper", "screenshot_minecraft"),
    "links" => array("shaarli")
);
$GLOBALS['config']['defaultDisplay'] = "blog";

$GLOBALS["title"] = "MyPuffBlob";

$GLOBALS["UPLOAD_ERROR"] = array(
    1 => "File too faty boom boom big",
    2 => "File too faty boom boom big",
    3 => "File is missing parts, lol",
    4 => "File was stole by ninja",
    6 => "Missing temporary folder, lolwut",
    7 => "Can i has pen ? I can write teh file"
);

define('PHPPRE','<?php /* '); // Prefix to encapsulate data in php code.
define('PHPSUF',' */ ?>'); // Suffix to encapsulate data in php code.

// Force cookie path (but do not change lifetime)
$cookie = session_get_cookie_params();
$cookiedir = ''; 
if (dirname($_SERVER['SCRIPT_NAME']) !== '/') {
    $cookiedir = dirname($_SERVER["SCRIPT_NAME"]).'/';    
} 
session_set_cookie_params($cookie['lifetime'], $cookiedir, $_SERVER['HTTP_HOST']); // Set default cookie expiration and path.

// Set session parameters on server side.
define('INACTIVITY_TIMEOUT',3600); // (in seconds). If the user does not access any page within this time, his/her session is considered expired.
ini_set('session.use_cookies', 1);       // Use cookies to store session.
ini_set('session.use_only_cookies', 1);  // Force cookies for session (phpsessionID forbidden in URL)
ini_set('session.use_trans_sid', false); // Prevent php to use sessionID in URL if cookies are disabled.
session_name('shaarli');
if (session_id() == '') {
    session_start();  // Start session if needed (Some server auto-start sessions).
}

define('UNLIKELY', 0.0000000001); 

include "inc/rain.tpl.class.php"; //include Rain TPL
raintpl::$tpl_dir = "tpl/"; // template directory
raintpl::configure( 'path_replace', false );

include "inc/markdown.php";
include "inc/markdown-extra.php";

class ES_GHF_Markdown_Parser extends MarkdownExtra_Parser {
        /**
         * Overload to enable single-newline paragraphs
         * https://github.com/github/github-flavored-markdown/blob/gh-pages/index.md#newlines
         */
        function formParagraphs( $text ) {
                // Treat single linebreaks as double linebreaks
                $text = preg_replace('#([^\n])\n([^\n])#', "$1\n\n$2", $text );
                return parent::formParagraphs( $text );
        }

        /**
         * Overload to support ```-fenced code blocks
         * https://github.com/github/github-flavored-markdown/blob/gh-pages/index.md#fenced-code-blocks
         */
        function doCodeBlocks( $text ) {
                $text = preg_replace_callback(
                        '#' .
                        '^```' . // Fenced code block
                        '[^\n]*$' . // No language-specific support yet
                        '\n' . // Newline
                        '(.+?)' . // Actual code here
                        '\n' . // Last newline
                        '^```$' . // End of block
                        '#ms', // Multiline mode + dot matches newlines
                        array( $this, '_doCodeBlocks_callback' ),
                        $text
                );

                return parent::doCodeBlocks( $text );
        }
}

require $GLOBALS['config']['CONF_FILE'];

header('Content-Type: text/html; charset=utf-8'); 

//___________________________________
// database class
class postDB implements Iterator, Countable, ArrayAccess {

    private $posts;
    private $keys;  
    private $position;
    private $hashList;
    private $loggedin;

    function __construct($isloggedin = false){
        $this->loggedin = $isloggedin;
        $this->check(); 
        $this->read(); 
    }

    // ### Countable interface implementation ###
    public function count() { 
        return count($this->posts); 
    }

    // ### ArrayAccess interface implementation ###
    public function offsetSet($offset, $value) {
        if (!$this->loggedin) {
            die('You are not authorized to add a link.');
        }
        if (empty($value['date']) || (empty($value['content']) && empty($value['file']))) {
            die('Internal Error: post should always have a date and content.');
        } 
        if (empty($offset)) {
            die('You must specify a key.');
        } 

        if($value['meta']['contentType'] === "file") {
            $hash = hash_file("sha1", $value['file']);
        } else {
            $hash = hash('sha1', trim($value['content']));
        }

        $value['hash'] = $hash;
        $this->posts[$offset] = $value;
        $this->hashList[$hash] = $value['date'];
        
    }
    public function offsetExists($offset) { 
        return array_key_exists($offset, $this->posts); 
    }
    public function offsetUnset($offset) {
        if (!$this->loggedin) {
            die('You are not authorized to add a link.');
        }
        $hash = $this->posts[$offset]['hash'];
        unset($this->posts[$offset]);
        unset($this->hashList[$hash]);
    }
    public function offsetGet($offset) { 
        return isset($this->posts[$offset]) ? $this->posts[$offset] : null; 
    }

    // ### Iterator interface implementation ###
    function rewind() { 
        $this->keys=array_keys($this->posts); 
        rsort($this->keys); 
        $this->position=0; 
    } 
    function key() { 
        return $this->keys[$this->position]; 
    }
    function current() { 
        return $this->posts[$this->keys[$this->position]]; 
    } 
    function next() { 
        ++$this->position; 
    } 
    function valid() { 
        return isset($this->keys[$this->position]); 
    }

    // ### Misc methods ###
    // check if database exists, create dummy one if not.
    private function check() {
        if (!file_exists($GLOBALS["config"]["DATABASE"])) {
            $this->posts = array();
            $post = array(
                "title" => "ceci est le titre",
                "date" => "20130529_115823",
                "content" => "ceci est le contenu (adresse, text, fichier ...)",
                "description" => "ceci est la description",
                "meta" => array(
                    "ext" => "",
                    "tags" => array("ce", "sont", "les", "tags"),
                    "lang" => "fr",
                    "keywords" => array("ceci", "chapô", "contenu", "est")
                ),
                "shapes" => array('blog', 'bookmarks'),
            );
            $post['hash'] = hash('sha1', trim($post['content']));
            $this->posts[$post] = $post['date'];
            $this->save();
        }
    }

    // read the entire database and store it in $this->posts
    private function read() {
        if (file_exists($GLOBALS['config']['DATABASE'])) {
            $content = file_get_contents($GLOBALS['config']['DATABASE']);
            $base64ed = substr($content, strlen(PHPPRE),-strlen(PHPSUF));
            $compressed = base64_decode($base64ed);
            $serialized = gzinflate($compressed);
            $this->posts = unserialize($serialized);
        } else {
            $this->posts = array();
        }
        
        if(!$this->loggedin) {
            foreach($this->posts as $date => $post) {
                if($post['privacy'] === "private") {
                    unset($this->posts[$date]);
                }
            }
        }

        $this->hashList = array();
        foreach ($this->posts as $post) {
            $this->hashList[$post['hash']] = $post['date'];
        }
    }

    // save the entire database to disk
    public function save() {
        if (!$this->loggedin) {
            die('You are not authorized to add a link.');
        }
        $serialized = serialize($this->posts);
        $compressed = gzdeflate($serialized);
        $base64ed = base64_encode($compressed);
        file_put_contents(
            $GLOBALS["config"]["DATABASE"], 
            PHPPRE.$base64ed.PHPSUF
        );
    }

    public function returnDateOfContent($contentType, $content) {
        if($contentType === "file") {
            $hash = hash_file("sha1", $content);
        } else {
            $hash = hash('sha1', trim($content));
        }

        if (isset($this->hashList[$hash])) {
            return $this->hashList[$hash];
        }
        return false;
    }

    public function filterShape($shape) {
        $filtered = array();
        foreach($this->posts as $date=>$post) {
            $shapes = $post["shapes"];
            if(!empty($shapes) && in_array($shape, $shapes)) {
                $filtered[$date] = $post;
            }
        }
        return $filtered;
    }

    public function filterTextData() {
        $filtered = array();
        foreach($this->posts as $date=>$post) {
            if(!empty($post['description']) || !empty($post['content'])) {
                $filtered[$date] = $post;
            }
        }
        return $filtered;
    }

    public function randomTextPost($length) {
        $textdata = $this->filterTextData();
        $shuffle = array();
        $keys = [];
        foreach($textdata as $date => $post) {
            $keys[] = $date;
        }
        shuffle($keys);
        foreach ($keys as $key) {
            $shuffle[$key] = $textdata[$key];
        }
        return array_slice($shuffle, 0, $length);
    }

}

include "inc/lang.php";
class lang {
    private $trigramIndex = array();
    private $languageCount = array();

    private function ngram($token, $n = 3) {
        $ngrams = array();
        $iso = utf8_decode($token);
        for ($i = 0; $i<strlen($iso) - $n + 1; $i++) {
            $ngrams[] = utf8_encode(substr($iso, $i, $n));
        }
        return $ngrams;
    }

    public function learn($text, $lang) {
        $tokens = tokenize($text);
        if (!isset($this->languageCount[$lang])) {
            $this->languageCount[$lang] = 0;
        }
        foreach ($tokens as $token) {
            $trigrams = $this->ngram($token, 2);
            foreach ($trigrams as $trigram) {
                if(!isset($this->trigramIndex[$trigram])) {
                    $this->trigramIndex[$trigram] = array();
                }
                if(!isset($this->trigramIndex[$trigram][$lang])) {
                    $this->trigramIndex[$trigram][$lang] = 0;
                }
                $this->trigramIndex[$trigram][$lang]++;
            }
            $this->languageCount[$lang] += count($trigrams);
        }
    }

    public function guess($text) {
        $tokens = tokenize($text);

        $foundTrigrams = array();
        $totalTrigrams = 0;
        foreach ($tokens as $token) {
            $trigrams = $this->ngram($token, 2);
            foreach ($trigrams as $trigram) {
                if(!isset($foundTrigrams[$trigram])) {
                    $foundTrigrams[$trigram] = 0;
                }
                $foundTrigrams[$trigram]++;
            }
            $totalTrigrams += count($trigrams);
        }

        $scores = array();
        foreach($this->languageCount as $lang=>$count) {
            $scores[$lang] = 0;
        }
        foreach ($foundTrigrams as $trigram=>$count) {
            if (!isset($this->trigramIndex[$trigram])) {
                continue;
            }
            $trigramLang = $this->trigramIndex[$trigram];
            foreach ($trigramLang as $lang=>$langCount) {
                /*if (!isset($scores[$lang])) {
                    $scores[$lang] = 0;
                }*/
                $score = $langCount / $this->languageCount[$lang];
                // occurence du trigram pour la langue / nbr total de trigram pour la langue = Représentativité du trigram pour la langue. ("xkv" pas representatif, en français. Pas d'occurence sur tout les trigrams).
                $score = $score * $count / $totalTrigrams;
                // occurence du trigram dans le text / nbr total de trigram dans le texte = Proportion du text qu'occupe ce trigram. Importance pour le texte.
                $scores[$lang] += $score;
            }
        }
        arsort($scores);
        return $scores;
    }

    private function serialize() {
        $data = array(
            "trigramIndex" => $this->trigramIndex,
            "languageCount" => $this->languageCount
        );
        $serialized = serialize($data);
        $compressed = gzdeflate($serialized);
        return base64_encode($compressed);
    }

    private function unserialize($string) {
        $compressed = base64_decode($string);
        $serialized = gzinflate($compressed);
        $data = unserialize($serialized);
        $this->trigramIndex = $data['trigramIndex']; 
        $this->languageCount = $data['languageCount']; 
    }

    public function save() {
        file_put_contents(
            $GLOBALS["config"]["LANG"], 
            PHPPRE.($this->serialize()).PHPSUF
        );
    }

    public function read() {
        if (file_exists($GLOBALS["config"]["LANG"])) {
            $content = file_get_contents($GLOBALS["config"]["LANG"]);
            $string = substr($content, strlen(PHPPRE),-strlen(PHPSUF));
            $this->unserialize($string);
        }
    }
}

include "inc/stopwords.php";
class keyword {

    private $corpus = array();
    private $corpusLength = 100;
    private $avgl = 0;
    private $k = 1.5;
    private $b = 0.75;

    public function initCorpus($db, $lang) {
        $dbreduce = $db->randomTextPost($this->corpusLength);
        foreach($dbreduce as $post) {
            $text = strtolower($post['content'] . " " . $post['description']);
            $tokens = tokenize($text);
            $this->avgl += count($tokens) / $this->corpusLength;
            $this->corpus[] = $tokens;
        }
        if(count($this->corpus) < $this->corpusLength) {
            $tofill = $this->corpusLength - count($db);
            for ($i = 0; $i < $tofill; $i++) {
                $tokens = $GLOBALS["stopwords"][$lang]; 
                $this->avgl += count($tokens) / $this->corpusLength;
                $this->corpus[] = $tokens;
            }
        }
    }

    private function IDF($token) {
        $occ = 0;
        foreach ($this->corpus as $text) {
            foreach($text as $word) {
                if ($token === $word) {
                    $occ++;
                    break;
                }
            }
        }
        return log(($this->corpusLength - $occ + 0.5) / ($occ + 0.5)); 
    }

    private function TF($text, $token) {
        $occ = 0;
        $words = tokenize($text);
        foreach($words as $word) {
            if($word === $token) {
                $occ ++;
            }
        }
        return $occ;
    }

    private function score($text, $token) {
        $tf = $this->TF($text, $token);
        $idf = $this->IDF($token);
        $k = $this->k;
        $b = $this->b;
        $nD = count(tokenize($text));
        $avgl = $this->avgl;
        $score = $idf * $tf * ($k + 1) / ($tf + $k * (1 - $b + $b * $nD / $avgl));
        return $score;
    }

    public function find($text, $nb) {
        $tokens = tokenize($text);
        $score = array();
        foreach($tokens as $token) {
            $score[$token] = $this->score($text, $token);
        }
        arsort($score);
        return array_slice($score, 0, $nb);
    }
}

class bayesianClassifier {
    private $words = array();
    private $classes = array();

    // ensure that there are no null entries for a given word or class.
    private function sanitize($wrd, $cls) {
        if(!isset($this->words[$wrd])) {
            $this->words[$wrd] = array();
        }
        if(!isset($this->words[$wrd][$cls])) {
            $this->words[$wrd][$cls] = 0;
        }

        if(!isset($this->classes[$cls])) {
            $this->classes[$cls] = array();
        }
        if(!isset($this->classes[$cls][$wrd])) {
            $this->classes[$cls][$wrd] = 0;
        }
    }

    private function teachWord($wrd, $cls) {
        $this->sanitize($wrd, $cls);
        $this->words[$wrd][$cls]++;
        $this->classes[$cls][$wrd]++;
    }

    // p(Class) probability of class in any document = the proportion en $cls amongst all classes
    private function pClass($cls) {
        $this->sanitize(null, $cls);
        $total = 0;
        foreach($this->classes as $class) {
            $total += count($class);
        }
        return count($this->classes[$cls]) / $total;
    }

    // p(Class|token) probability a give word is classified as cls.
    private function pClassGivenToken($cls, $token) {
        $this->sanitize($token, $cls);
        // bayes theorem
        $num = $this->words[$token][$cls] / count($this->classes[$cls]) * $this->pClass($cls);
        $denom = 0;
        foreach($this->classes as $class => $wordlist) {
            $this->sanitize($token, $class);
            $denom += $this->words[$token][$class] / count($this->classes[$class]) * $this->pClass($class);
        }
        if($denom < UNLIKELY) { // word is unknown for all classes || unknown for $cls
            //echo $cls."|".$token." = 0.0001<br/>";
            return UNLIKELY; // keeping zero will result in divide by zero later.
        } else{
            //echo $cls."|".$token." = ".round($num*1000)." / ".round($denom*1000)."<br/>";
            return min(max($num / $denom, UNLIKELY), 1-UNLIKELY);
        }
    }

    // p(Class|tokenList) Take a set of words and compute a probibility that the document belongs to the supplied class.
    private function pClassGivenDoc($cls, $tokens) {
        $lnProb = 0;
        foreach($tokens as $token) {
            $p = $this->pClassGivenToken($cls, $token);
            $lnProb += log(1-$p) - log($p); // To avoid underflow for products of probability getting smaller and small, taking the log.
        }
        return $lnProb;
    }

    public function teach($text, $cls) {
        $tokens = tokenize($text);
        foreach ($tokens as $token) {
            $this->teachWord($token, $cls);
        }
    }

    public function guess($text) {
        $tokens = tokenize($text);
        $probSum = 0;
        $classes = array();
        foreach($this->classes as $cls => $wordlist) {
            $lnProb = $this->pClassGivenDoc($cls, $tokens);
            $prob = 1 / (1 + exp($lnProb));
            $classes["$prob"] = $cls; 
        }
        krsort($classes);
        return $classes;
    }

    private function serialize() {
        $data = array(
            "words" => $this->words,
            "classes" => $this->classes
        );
        $serialized = serialize($data);
        $compressed = gzdeflate($words);
        return base64_encode($compressed);
    }

    private function unserialize($string) {
        $compressed = base64_decode($string);
        $serialized = gzinflate($compressed);
        $data = unserialize($serialized);
        $this->words = $data['words']; 
        $this->classes = $data['classes']; 
    }

    /*public function save() {
        file_put_contents(
            $GLOBALS["config"]["BAYES"], 
            PHPPRE.($this->serialize()).PHPSUF
        );
    }
    
    public function read() {
        if (file_exists($GLOBALS["config"]["BAYES"])) {
            $content = file_get_contents($GLOBALS["config"]["BAYES"]);
            $string = substr($content, strlen(PHPPRE),-strlen(PHPSUF));
            $this->unserialize($string);
        }
    }*/
}

/*class shapeClassifier {
    private $keywordClassifier;
    private $tagsClassifier;
    private $langClassifier;
}*/

//___________________________________
// pageBuilder using rain TPL
class pageBuilder {
    private $tpl; 

    function __construct() {
        $this->tpl=false;
        $this->initialize();
    }

    private function initialize() {
        $this->tpl = new RainTPL;
        $this->tpl->assign('puffblobTitle', empty($GLOBALS['title']) ? 'PuffBlob': $GLOBALS['title'] );
        return;
    }

    // The following assign() method is basically the same as RainTPL (except that it's lazy)
    public function assign($what, $where) {
        if ($this->tpl===false) $this->initialize(); // Lazy initialization
        $this->tpl->assign($what, $where);
    }

    // Render a specific page (using a template).
    // eg. pb.renderPage('picwall')
    public function renderPage($page) {
        if ($this->tpl===false) $this->initialize(); // Lazy initialization
        $this->tpl->draw($page);
    } 
}

class login {

    public static function check($password, $login) {
        $hash = hash("sha1", $password . $login . $GLOBALS['salt']);
        if($GLOBALS['hash'] === $hash && $GLOBALS['login'] === $login) {
            $_SESSION['uid'] = sha1(uniqid('', true) . '_' . mt_rand());
            $_SESSION['ip'] = getIP();
            $_SESSION['username'] = $login;
            $_SESSION['expires_on'] = time() + INACTIVITY_TIMEOUT;
            return true;
        }
        return false;
    }

    public static function isLoggedIn() {
        if (empty($_SESSION['uid']) ||
            ($GLOBALS["disable_session_protection"] && $_SESSION['ip'] !== getIP()) ||
            time() >= $_SESSION["expires_on"]) {
            self::logout();
            return false;
        }
        $_SESSION['expires_on'] = time() + INACTIVITY_TIMEOUT;
        return true;
    }

    public static function logout() {
        if(isset($_SESSION)) {
            unset($_SESSION['uid']);
            unset($_SESSION['ip']);
            unset($_SESSION['username']);
            unset($_SESSION['privateonly']);
        }
    }
}

class ipBan {

    static function init() {
        if (!is_file($GLOBALS['config']['IPBANS_FILENAME'])) {
            $varExport = var_export(array('FAILURES'=>array(),'BANS'=>array()),true);
            $content = "<?php\n\$GLOBALS['IPBANS']=".$varExport.";\n?>";
            file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], $content);
        }
        include $GLOBALS['config']['IPBANS_FILENAME'];
    }

    static function loginFailed() {
        $ip = getIP();
        $ipban = $GLOBALS['IPBANS'];
        if (!isset($ipban['FAILURES'][$ip])) {
            $ipban['FAILURES'][$ip] = 0;
        }
        $ipban['FAILURES'][$ip]++;
        if($ipban['FAILURES'][$ip] > ($GLOBALS['config']['BAN_AFTER'] -1)) {
            $ipban['BANS'][$ip] = time() + $GLOBALS['config']['BAN_DURATION'];
        }
        $GLOBALS['IPBANS'] = $ipban;
        self::writeBanFile($ipban);
    }

    static function loginOK() {
        $ip = getIP();
        $ipban = $GLOBALS['IPBANS'];
        unset($ipban['FAILURES'][$ip]); 
        unset($ipban['BANS'][$ip]);
        $GLOBALS['IPBANS'] = $ipban;
        self::writeBanFile($ipban);
    } 

    static function canLogin() {
        $ip = getIP();
        $ipban = $GLOBALS['IPBANS'];
        if (isset($ipban['BANS'][$ip])) {
            // User is banned. Check if the ban has expired:
            if ($ipban['BANS'][$ip] <= time()) {   
                // Ban expired, user can try to login again.
                unset($ipban['FAILURES'][$ip]); 
                unset($ipban['BANS'][$ip]);
                self::writeBanFile($ipban);
                return true; // Ban has expired, user can login.
            }
            return false; // User is banned.
        }
        return true; // User is not banned.
    }

    private function writeBanFile($ipban) {
        $varExport = var_export($ipban, true);
        $content = "<?php\n\$GLOBALS['IPBANS']=".$varExport.";\n?>";
        file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], $content);
    }
}

class token {

    static function init() {
        if(!isset($_SESSION['tokens'])) {
            $_SESSION['tokens'] = array();
        }
    }

    static function getToken() {
        $rnd = hash('sha1', uniqid('', true) . '_' . mt_rand() . $GLOBALS['salt']);
        $_SESSION['tokens'][$rnd] = 1;
        return $rnd;
    }

    static function checkToken($token) {
        if(isset($_SESSION['tokens'][$token])) {
            unset($_SESSION['tokens'][$token]);
            return true;
        }
        return false;
    }
}


class img {

    static function makeThumb($filename) {

    }
}

//___________________________________
// router to call correct function
class router {

    private $posts;
    private $pageBuilder;
    private $parser;
    private $page;

    function __construct() {
        $this->posts = new postDB(login::isLoggedIn());
        $this->pageBuilder = new pageBuilder;
        $this->parser = new ES_GHF_Markdown_Parser;
    }

    public function route() {
        if(isset($_GET["page"]) {
            $this->$page = $_GET["page"];
            unset($_GET["page"])
        }

        //posting login form
        if(isset($_POST["login"])) {
            $this->validateLogin();
            exit;
        }
        //login page
        if(isset($_GET["login"])) { 
            $this->loginPage(($_GET["login"] === "")? 1 : 0);
            exit;
        }
        if(isset($_GET["file"])) {
            $this->getFile($_GET["file"]);
            exit;
        }
        if(isset($_GET["shape"]) && empty($_POST)) {
            $this->displayShape($_GET["shape"]);
            exit;
        }
        if(empty($_GET) && empty($_POST)) {
            $this->displayShape("all");
            exit;
        }

        // other route for non-logged users
        if(!login::isLoggedIn()) {
            $this->loginPage(1);
            exit;
        }

        //posting post form (logged user)
        if(isset($_POST["post"])) { 
            $this->sendPost();
            exit;
        }
        //posting post form (logged user)
        if(isset($_POST["delete"])) { 
            $this->deletePost();
            exit;
        }  
        //posting edit form (logged user)
        if(isset($_POST["edit"])) { 
            $this->sendPost();
            exit;
        }  
        //posting analysis request (logged user)
        if(isset($_POST["analyse"])) { 
            //var_dump($_POST);
            //var_dump($_FILES);
            $this->analysePost();
            exit;
        }  
        //posting preview request (logged user)
        if(isset($_POST["preview"])) { 
            //var_dump($_POST);
            //var_dump($_FILES);
            echo $this->parser->transform($_POST['text']);
            exit;
        }  

        //addpost page (logged user)
        if(isset($_GET["addpost"])) { 
            $this->addPostPage();
            exit;
        }
        //login page
        if(isset($_GET["logout"])) { 
            $this->sendLogout();
            exit;
        }
        //edit page
        if(isset($_GET["edit"])) { 
            $this->editPost($_GET['date']);
            exit;
        }

        $this->postList();
        exit;
    } 

    private function displayShape($reqShape) {
        $reqDisplay = "";
        foreach($GLOBALS['config']['display'] as $display=>$shapes) {
            if(in_array($reqShape, $shapes)) {
                $reqDisplay = $display;
                break;
            }
        }
        if($reqDisplay === "") {
            $reqDisplay = $GLOBALS["config"]['defaultDisplay'];
            $reqShape = "all"; //pas de display trouvé => shape inexistante, donc montrer tout.
        }
        $this->buildLinkList($reqShape);
        $this->pageBuilder->assign('token', token::getToken());
        if(!file_exists('tpl/'.$reqDisplay.".html")) {
            $reqDisplay = $GLOBALS["config"]['defaultDisplay'];
        }
        echo $reqDisplay;
        $this->pageBuilder->renderPage($reqDisplay);

    }

    private function getFile($date) {
        $target = $this->posts[$date];
        if($target["file"] !== "") {
            $document = $target["file"];
            if(file_exists($document)) {
                header('Content-type: application/octet-stream');
                header('Content-Disposition: attachement; filename="' . $target["meta"]["filename"] . '"');
                readfile($document);
            }
        }
    }

    private function loginPage($loginOK) {
        $attempts = $GLOBALS['config']['BAN_AFTER'];
        if(!$loginOK) {
            $ip = getIP();
            if(isset($GLOBALS['IPBANS']['FAILURES'][$ip])) {
                $attempts =  $GLOBALS['config']['BAN_AFTER'] - $GLOBALS['IPBANS']['FAILURES'][$ip];                
            }
        }
        $token = '';
        if(ipBan::canLogin()) {
            $token = token::getToken();
        }

        $this->pageBuilder->assign('token', $token);
        $this->pageBuilder->assign('attempts', $attempts);
        $this->pageBuilder->assign('loginOK', $loginOK);
        $this->pageBuilder->renderPage("login");
    }

    private function validateLogin() {
        if(!ipBan::canLogin()) {
            die('Cannot haz authentification, you iz banned. shoo.');
        }
        if(login::check($_POST['password'], $_POST['login']) && token::checkToken($_POST['token'])) {
            ipBan::loginOK();
            $cookiedir = ''; 
            if(dirname($_SERVER['SCRIPT_NAME'])!='/') { 
                $cookiedir=dirname($_SERVER['SCRIPT_NAME']).'/';
            }
            session_set_cookie_params(0, $cookiedir, $_SERVER['HTTP_HOST']);
            session_regenerate_id(true);
            header("Location: ?");
        }
        else {
            ipBan::loginFailed();
            header("Location: ?login=0");
        }
    }

    public function buildLinkList($shape = "all") {
        if($shape === "all") {
            $posts = $this->posts;
        } else {
            $posts = $this->posts->filterShape($shape);
        }
        $keys = array();
        foreach($posts as $key => $value) {
            $keys[] = $key;
        }
        $postsPerPage = $GLOBALS["config"]["POSTS_PER_PAGE"];


        $pagecount = ceil(count($keys) / $postsPerPage);
        $pagecount = ($pagecount==0 ? 1 : $pagecount);

        $pageNum = 1; // GET page value

        $pageNum = ($pageNum < 1) ? 1 : $pageNum;
        $pageNum = ($pageNum > $pagecount) ? $pagecount : $pageNum;

        $i = ($pageNum - 1) * $postsPerPage; // Start index.
        $end = $i + $postsPerPage;

        $postsToDisplay = array();
        while ($i < $end && $i < count($keys))
        {
            $post = $posts[$keys[$i]];
            $post['content'] = $this->parser->transform($post['content']);
            $post['description'] = $this->parser->transform($post['description']);
            $postsToDisplay[$keys[$i]] = $post;
            $i++;
        }

        krsort($postsToDisplay);

        $token = '';
        if(ipBan::canLogin()) {
            $token = token::getToken();
        }

        $this->pageBuilder->assign('posts', $postsToDisplay);
        $this->pageBuilder->assign('token', $token);
    }


    /*private function postList($shape = "all") {
        $this->buildLinkList($shape);
        $this->pageBuilder->assign('token', token::getToken());
        $this->pageBuilder->renderPage("postlist");
    }*/  

    private function addPostPage() {
        $this->pageBuilder->assign('token', token::getToken());
        $this->pageBuilder->assign('hasFile', "false");
        $this->pageBuilder->renderPage("addpost");
    } 

    private function sendPost() {
        $data = json_decode($_POST["data"], true);

        if(!token::checkToken($data['token'])) {
            die("Nope nope nope");
        }

        if (!isset($_FILES["file"]) && !isset($data["content"])) {
            die("lol post is empty, wtf ?");
        }

        $contentType = $data['meta']['contentType'];
        if($contentType === "file") {
            if($data['keepoldfile'] === "true") {
                $content = $data['oldfile'];
            } else {
                $content = $_FILES["file"]["tmp_name"];
            }
        } else {
            $content = $data['content'];
        }

        // Si un post existe déja à cette date là, mais que ce n'est pas le post
        // qu'on est en train d'éditer, on redirige vers l'edition du post qui éxiste déja.
        $date = $this->posts->returnDateOfContent($contentType, $content);
        if(!$date || (isset($data['date']) && $data['date'] === $date)) {
            $this->savePost();
        } else {
            echo '<script>window.location.replace("?date=' . $date . '&edit=edit&notnew=notnew");</script>';
        }
    }

    private function sendEdit() {
        if(!token::checkToken($_POST['token'])) {
            die("Nope nope nope");
        }

        $this->savePost();
    }

    private function savePost() {
        $data = json_decode($_POST["data"], true);

        $contentType = $data['meta']['contentType'];
        $keepoldfile = $data['keepoldfile'];
        $oldfile = $data['oldfile'];
        $file = ($contentType === "file" && $keepoldfile === "false") ? $_FILES["file"] : "";
        $filename = $data['meta']["filename"];
        $filesize = $data['meta']["filesize"];
        $date = $data["date"];
        $fileURL = "";
        $content = $data['content'];
        $editHistory = $data['editHistory'];
        $private = $data['privacy'];

        var_dump($data);
        var_dump($_POST);
        var_dump($_FILES);

        $ext = "";
        if($contentType === "file") {
            if($keepoldfile === "true") {
                $fileURL = $oldfile;
            } elseif ($file !== "") {
                if ($file["error"]) {
                    echo $GLOBALS["UPLOAD_ERROR"][$file["error"]];
                }
                $info = pathinfo($file["name"]);
                $ext = $info["extension"];
                $newname = $date . "." . $ext;

                $fileURL = "files/".$newname;
                echo $oldfile;
                echo $fileURL;
                if(file_exists($oldfile)){
                    echo "delete";
                    unlink($oldfile);
                }
                move_uploaded_file($file["tmp_name"], $fileURL);
            } 
        }else {
            if(file_exists($oldfile)){
                echo "delete";
                unlink($oldfile);
                $filename = "";
                $ext = "";
            }
        }

        $description = $data["description"];
        $title = $data["title"];
        $tags = $data['meta']["tags"];
        $keywords = $data['meta']["keywords"];
        $shapes = $data["shapes"];
        $lang = $data['meta']["lang"];

        $post = array(
            "title" => $title,
            "date" => $date,
            "editHistory" => $editHistory,
            "content" => $content,
            "file" => $fileURL,
            "description" => $description,
            "privacy" => $private,
            "meta" => array(
                "contentType" => $contentType,
                "contentLength" => strlen($content),
                "filename" => $filename,
                "ext" => $ext,
                "mimeType" => $GLOBALS['mime'][$ext],
                "tags" => $tags,
                "lang" => $lang,
                "keywords" => $keywords,
                "filesize" => $filesize
            ),
            "shapes" => $shapes
        );

        var_dump($post);

        $this->posts[$post["date"]] = $post;
        $this->posts->save();
        echo "No problemz bro";
    }

    private function analysePost() {
        $lang = new lang;
        $keyword = new keyword;

        $lang->learn($GLOBALS["lang"]["en"], "en");
        $lang->learn($GLOBALS["lang"]["fr"], "fr");

        $postLang = $lang->guess($_POST['text']);

        $keyword->initCorpus($this->posts, "fr");//$GLOBALS['config']['LOCALE']);
        $scores = $keyword->find($_POST['text'], 10);
        $keywords = array();
        foreach($scores as $keyword => $score) {
            $keywords[] = $keyword;
        }

        $analysis = array(
            "keywords" => $keywords,
            "lang" => key($postLang)
        );


        echo json_encode($analysis);
    }

    private function editPost($date) {
        $post = $this->posts[$date];
        if (!$post) {
            header('Location: ?');
        }

        $this->pageBuilder->assign('post', $post);
        $this->pageBuilder->assign('notnew', isset($_GET['notnew']) ? true : false);
        $this->pageBuilder->assign('post', $post);
        $this->pageBuilder->assign('token', token::getToken());
        $this->pageBuilder->assign('contentType', $post['meta']['contentType']);
        $this->pageBuilder->assign('filesize', $post['meta']['filesize']);
        $this->pageBuilder->assign('filename', $post['meta']['filename']);
        $this->pageBuilder->assign('private', $post['privacy'] === "private");
        $this->pageBuilder->renderPage('editpost');
    }

    private function deletePost() {
        if(!token::checkToken($_POST['token'])) {
            die('Wrong token');
        }

        $date = $_POST['date'];
        $file = $this->posts[$date]['file'];
        if(file_exists($file)) {
            unlink($file);
        }
        unset($this->posts[$date]);
        $this->posts->save();

        header("Location: ?");
    }

    private function sendLogout() {
        login::logout();
        header("Location: ?");
    }
}

function stringList2Array($tagList) {
    $tagList = preg_replace("/ *, */", ",", $tagList);
    return explode(",", $tagList);
}

function getIP() {
    $ip = $_SERVER["REMOTE_ADDR"];
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) { 
        $ip = $ip . '_' . $_SERVER['HTTP_X_FORWARDED_FOR']; 
    }
    if (isset($_SERVER['HTTP_CLIENT_IP'])) { 
        $ip = $ip . '_' . $_SERVER['HTTP_CLIENT_IP']; 
    }
    return $ip;
}

function isStringRelativeURL($string) {
    $isRelativeURL = array();
    if(strrpos($string, " ") === false) {
        $isRelativeURL = preg_match('/^(?:[a-zA-Z0-9_]*\/)*[a-zA-Z0-9_]*\.[a-zA-Z0-9_]*$/', $string);        
    }
    return !empty($isRelativeURL);
}

// Ugly date formatting ...
function formatDate($ts, $format) {
    $unixts = floor($ts/1000);
    $date = date($format, $unixts);
    $cible = array('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday','January','February','March','April','May','June','July','August','September','October','November','December');
    $rempl = $GLOBALS['traduction']['date'][$GLOBALS['config']['LOCALE']];
    return str_replace($cible, $rempl, $date);
}

function tokenize($text, $preserveURL = true) {
    $lowercase = strtolower($text);

    $matches = array();
    preg_match_all('/\b((?:ht|f)tps?):\/\/([a-zA-Z\.]+)([\/a-zA-Z\.]+)*/', $text, $matches);
    $urls = $matches[0];
    preg_replace('/\b((?:ht|f)tps?):\/\/([a-zA-Z\.]+)([\/a-zA-Z\.]+)*/', "çççURLççç", $text);

    $nopunct = preg_replace('/[^A-Za-z0-9ÀàÂâÆæÇçÉéÈèÊêËëÎîÏïÔôŒœÙùÛûÜüŸÿ]/', ' ', $lowercase);
    $singlespace = preg_replace('/ +/', ' ', $nopunct);
    $tokens = explode(' ', $singlespace);
    $urlIndex = 0;
    foreach($tokens as $token) {
        if($token === "çççURLççç") {
            $token = $urls[$urlIndex];
            $urlIndex ++;
        }
    }
    $cleanTokens = array();
    foreach ($tokens as $token) {
        if(!empty($token)) {
            $cleanTokens[] = $token;
        }
    }
    return $cleanTokens;
}


/*function autoLocale(){
    $loc = 'en_US'; // Default if browser does not send HTTP_ACCEPT_LANGUAGE
    setlocale(LC_TIME, array($loc, "us"));
}*/


ipBan::init();
token::init();

$router = new router;
$router->route();


// add markdown from https://github.com/evansolomon/wp-github-flavored-markdown-comments/tree/master/lib

?>