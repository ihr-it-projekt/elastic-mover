<?php

$input        = "";
$input_type   = 0;
$output       = "";
$output_type  = 0;
$verbose      = 0;
$chunk_size   = 1000;
$bulk_size    = 1000;
$verbose_mode = 1;
$data_type    = 0;
$use_pattern  = false;

if ($argc == 1) {
    echo "ElasticMover 0.1.2\n";
    echo "Usage: php elasticmover.php -i=<input path> -o=<output path> [options...]\n";
    echo "Options: \n";
    echo " -i=<elasticsearch index url or file path>\texample: -i=http://localhost:9200/index or /path/to/file\n";
    echo " -o=<elasticsearch index url or file path>\texample: -o=http://localhost:9200/index or /path/to/file\n";
    echo " -p index in url will used as regex pattern. All indexes who match string will exported in one file. Parameter is only used in export process." . PHP_EOL;
} else {
    foreach ($argv as $key => $value) {
        $arg = explode("=", $value);
        if (count($arg) == 2) {
            switch ($arg[0]) {
                case "-i":
                    $i_url_parse = parse_url($arg[1]);
                    if ($i_url_parse) {
                        if ($i_url_parse['path'] != "") {
                            if (!isset($i_url_parse['scheme'], $i_url_parse['host']) || ($i_url_parse['scheme'] == "" && $i_url_parse['host'] == "")) {
                                $input      = $arg[1];
                                $input_type = 1; // file input type
                            } else {
                                $path_explode = explode("/", $i_url_parse['path']);
                                if (count($path_explode) == 2) {
                                    if ($path_explode[0] == "" && $path_explode[1] != "") {
                                        $input      = $arg[1];
                                        $input_type = 0; // url input type
                                    } else {
                                        echo "Malformed input url, please use this format: {protocol}://{host}:{port}/{index}\n";
                                    }
                                }
                            }
                        } else {
                            echo "Input argument error\n";
                        }
                    }
                    break;
                case "-o":
                    $o_url_parse = parse_url($arg[1]);
                    if ($o_url_parse) {
                        if ($o_url_parse['path'] !== "") {
                            if (!isset($o_url_parse['scheme'], $o_url_parse['host']) || ($o_url_parse['scheme'] == "" && $o_url_parse['host'] == "")) {
                                $output      = $arg[1];
                                $output_type = 1; // file input type
                            } else {
                                $path_explode = explode("/", $o_url_parse['path']);
                                if (count($path_explode) == 2) {
                                    if ($path_explode[0] == "" && $path_explode[1] != "") {
                                        $output      = $arg[1];
                                        $output_type = 0; // url input type
                                    } else {
                                        echo "Malformed output url, please use this format: {protocol}://{host}:{port}/{index}\n";
                                    }
                                }
                            }
                        } else {
                            echo "Output argument error\n";
                        }
                    }
                    break;
                case "-c":
                    if (is_numeric($arg[1]) && $arg[1] > 0) {
                        $chunk_size = $arg[1];
                    }
                    break;
                case "-b":
                    if (is_numeric($arg[1]) && $arg[1] > 0) {
                        $bulk_size = $arg[1];
                    }
                    break;
            }
        } else {
            switch ($value) {
                case "-v":
                    $verbose_mode = 2;
                    break;
                case "-vv":
                    $verbose_mode = 3;
                    break;
                case "-d":
                    $data_type = 0;
                    break;
                case "-m":
                    $data_type = 1;
                    break;
                case "-p":
                    $use_pattern = true;
            }
        }
    }

    if ($input != "" && $output != "") {
        if ($input_type == 0 && $output_type == 1) {
            echo "$input ---";
            echo $data_type ? 'map' : 'data';
            echo "---> $output\n";
            try {
                $inputs = preImExport($input, $use_pattern);
                foreach ($inputs as $input) {
                    echo sprintf('Export: %s ', $input) . PHP_EOL;
                    es2file($input, $output, $data_type, $verbose_mode, $chunk_size);
                }
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        } elseif ($input_type == 1 && $output_type == 0) {
            echo "$input ---";
            echo $data_type ? 'map' : 'data';
            echo "---> $output\n";
            file2es($input, $output, $data_type, $verbose_mode, $bulk_size);
        } elseif ($input_type == 0 && $output_type == 0) {
            echo "File to File transfer is not supported\n";
        }
    } else {
        echo "Input or Output source is not specified\n";
    }
}

/**
 * @param string $es_url
 * @param bool   $use_pattern
 *
 * @return array
 */
function preImExport($es_url, $use_pattern)
{
    $es_urls = [];

    $pattern = '';

    if ($use_pattern === true) {
        $pos     = strrpos($es_url, "/", -1);
        $pattern = substr($es_url, $pos + 1);

        $serverUrl = str_replace($pattern, '', $es_url);

        $es      = new ElasticSearch($serverUrl);
        $indexes = $es->getIndexes();

        if (empty($indexes)) {
            $message = sprintf('Can not load indexes from "%s"', $serverUrl);
            throw new \Exception($message);
        }

        foreach ($indexes as $index => $value) {
            if (preg_match(sprintf('/%s/', $pattern), $index)) {
                echo sprintf('Add index: %s to export. ', $index) . PHP_EOL;
                $es_urls[] = sprintf('%s%s', $serverUrl, $index);
            }
        }
    } else {
        $es_urls[] = $es_url;
    }

    if (empty($es_urls)) {
        $message = sprintf('Can not find an index that match pattern "%s"', $pattern) . PHP_EOL;
        throw new \Exception($message);
    }

    return $es_urls;
}

/**
 * @param     $es_url
 * @param     $output
 * @param     $data_type
 * @param int $verbose_mode
 * @param int $chunk_size
 *
 * @internal param $index
 */
function es2file($es_url, $output, $data_type, $verbose_mode = 1, $chunk_size = 1000)
{
    if ($data_type === 1) {
        //Index map
        $es            = new ElasticSearch($es_url);
        $map           = $es->getMapping();
        $es_url_parsed = parse_url($es_url);
        $index         = str_replace("/", "", $es_url_parsed['path']);
        writeDataToFile(json_encode($map->{$index}), $output);
        echo "$index map saved\n";
    } else {
        //Index data
        $count_docs    = 0;
        $chuck_counter = 0;
        $dump          = "";

        $es    = new ElasticSearch($es_url);
        $query = '{
              "query": {
                "bool": {
                  "must": [{"match_all":{}}],
                  "must_not": [],
                  "should": []
                }
              }
            }';

        $scroll_ret = $es->createScrollID($query);

        if (!$scroll_ret || !isset($scroll_ret->_scroll_id, $scroll_ret->hits, $scroll_ret->hits->total)) {
            echo sprintf('No scroll data found. Maybe index: "%s" in query closed?', $es_url) . PHP_EOL;

            return;
        }

        $scroll_id  = $scroll_ret->_scroll_id;
        $total_docs = $scroll_ret->hits->total;

        if ($verbose_mode > 1) {
            echo "[" . date('c') . "] Scroll ID created: " . $scroll_id . "\n";
        }

        while (true) {
            $docs_ret = $es->getScrollData($scroll_id);
            if ($docs_ret != null) {
                if (isset($docs_ret->hits->hits)) {
                    $docs = $docs_ret->hits->hits;
                } else {
                    echo "[" . date('c') . "] Get Scroll docs error: \n";
                    print_r($docs_ret);
                    echo "\n";

                    return null;
                }
                $count_docs += count($docs);

                if ($verbose_mode > 1) {
                    echo "[" . date('c') . "] Scroll docs: " . count($docs) . ", ($count_docs/$total_docs)\n";
                }

                if (count($docs) == 0) {
                    if ($verbose_mode > 1) {
                        echo "[" . date('c') . "] Dump Result: $count_docs docs\n";
                    }

                    return 0;
                }

                foreach ($docs as $r => $row) {
                    if ($verbose_mode == 3) {
                        echo "[" . date('c') . "] Read doc: $row->_id\n";
                    }
                    if ($verbose_mode == 1) {
                        show_status($count_docs, $total_docs, $size = 40);
                    }
                    $bk_object = createBackupDocObj($row, "");
                    $dump      .= $bk_object;
                    $chuck_counter++;
                }

                if ($chuck_counter > $chunk_size) {
                    appendDataToFile($dump, $output);
                    if ($verbose_mode > 1) {
                        echo "[" . date('c') . "] Save docs to dump file: saved $chuck_counter docs\n";
                    }
                    $chuck_counter = 0;
                    $dump          = "";
                }
            } else {
                if ($dump != "") {
                    appendDataToFile($dump, $output);
                    if ($verbose_mode > 1) {
                        echo "[" . date('c') . "] Save docs to dump file: saved $chuck_counter docs\n";
                    }
                    $chuck_counter = 0;
                    $dump          = "";
                }

                return;
            }
        }
    }
}

/**
 * @param string $input
 * @param string $es_url
 * @param int    $data_type
 * @param int    $verbose_mode
 * @param int    $bulk_size
 *
 * @internal param int $chunk_size
 * @internal param $output
 * @internal param $index
 * @internal param $file
 */
function file2es($input, $es_url, $data_type, $verbose_mode = 1, $bulk_size = 1000)
{
    if ($data_type == 1) {
        // Index Map
        echo "[" . date('c') . "] Reading map file\n";
        $handle   = fopen($input, "rb");
        $contents = fread($handle, filesize($input));
        fclose($handle);

        $maps = json_decode($contents);
        $es   = new ElasticSearch($es_url);
        foreach ($maps as $key => $value) {
            $map       = [];
            $map[$key] = $value;
            $map_res   = $es->setMapping($key, json_encode($map));
            echo "$key type saved: " . json_encode($map_res) . "\n";
        }
    } else {
        // Index Data
        $total_line_count = 0;
        $line_count       = 0;
        $bulk_count       = 0;
        $obj_count        = 0;

        echo "[" . date('c') . "] Reading data file\n";
        $handle = fopen($input, "r");
        while (!feof($handle)) {
            fgets($handle);
            $total_line_count++;
        }
        fclose($handle);

        echo "[" . date('c') . "] Starting data import\n";
        $obj_buffer = "";
        $data       = "";
        $handle     = fopen($input, "r");
        while (!feof($handle)) {
            $line = fgets($handle);
            if (strpos($line, '_index') !== false) {
                $obj_buffer = $line;
            } else {
                $obj_buffer .= $line;
                $data       .= $obj_buffer;
                $obj_count++;
                $obj_buffer = "";
            }

            $bulk_count++;
            $line_count++;

            if (bulkData($es_url, $data, $bulk_count, $bulk_size)) {
                $bulk_count = 0;
                $data       = "";
                show_status($line_count, $total_line_count);
            }
        }

        if (bulkData($es_url, $data, $bulk_count, 0)) {
            $bulk_count = 0;
            $data       = "";
            show_status($line_count, $total_line_count);
        }

        fclose($handle);
        echo "\n";
        echo "[" . date('c') . "] Imported $obj_count docs\n";
    }
}

/**
 * @param $index
 * @param $file
 */
function es2es($index, $file)
{
}

/**
 * @param $object
 *
 * @return string
 */
function createBackupDocObj($object)
{
    $data = "";
    $data .= '{ "index" : { "_index" : "' . $object->_index . '", "_type" : "' . $object->_type . '", "_id" : "' . $object->_id . '" } }' . "\n";
    $data .= json_encode($object->_source) . "\n";

    return $data;
}

function appendDataToFile($data, $file)
{
    $fh = fopen($file, 'a') or die("can't open file");
    fwrite($fh, $data);
    fclose($fh);
}

function bulkData($es_url, $data, $count, $limit)
{
    if ($count >= $limit) {
        $es  = new ElasticSearch($es_url);
        $res = $es->bulk($data);
        sleep(2);

        return true;
    }

    return false;
}

function writeDataToFile($data, $file)
{
    $fh = fopen($file, 'w') or die("can't open file");
    fwrite($fh, $data);
    fclose($fh);
}

/**
 * Class ElasticSearch
 */
class ElasticSearch
{
    /**
     * @param string $server
     */
    public function __construct($server = 'http://localhost:9200')
    {
        $this->server = $server;
    }

    /**
     * @param $query
     *
     * @return mixed|null
     * @throws Exception
     */
    public function createScrollID($query)
    {
        return $this->call(
            '_search?search_type=scan&scroll=1m&size=50',
            ['method' => 'GET', 'header' => 'Content-Type: application/json\r\n', 'content' => $query]
        );
    }

    /**
     * @param $id
     *
     * @return mixed|null
     * @throws Exception
     */
    public function getScrollData($id)
    {
        return $this->call(
            '_search/scroll?scroll=1m',
            ['method' => 'GET', 'header' => 'Content-Type: application/json\r\n', 'content' => $id],
            false
        );
    }

    public function clearAllScrollID()
    {
        return $this->call(
            'search/scroll/_all',
            ['method' => 'DELETE', 'header' => 'Content-Type: application/json\r\n'],
            false
        );
    }

    /**
     * @return mixed|null
     */
    public function getMapping()
    {
        return $this->call('_mapping', ['method' => 'GET', 'header' => 'Content-Type: application/json\r\n']);
    }

    /**
     * @param $type
     * @param $map
     *
     * @return mixed|null
     */
    public function setMapping($type, $map)
    {
        return $this->call(
            '/' . $type . '/_mapping',
            ['method' => 'PUT', 'header' => 'Content-Type: application/json\r\n', 'content' => $map]
        );
    }

    /**
     * @param $data
     *
     * @return mixed|null
     */
    public function bulk($data)
    {
        return $this->call(
            '_bulk',
            ['method' => 'POST', 'header' => 'Content-Type: application/json\r\n', 'content' => $data]
        );
    }

    /**
     * @return array|null
     */
    public function getIndexes()
    {
        return $this->call(
            '_aliases',
            ['method' => 'GET', 'header' => 'Content-Type: application/json\r\n'],
            true,
            true
        );
    }

    /**
     * @param       $path
     * @param array $http
     * @param bool  $include_index
     *
     * @return mixed|null
     */
    private function call($path, array $http = [], $include_index = true, $getAssArray = false)
    {
        try {
            if (!$include_index) {
                $url_server = parse_url($this->server);
                $server     = str_replace($url_server['path'], "", $this->server);
            } else {
                $server = $this->server;
            }
            $response = @file_get_contents($server . '/' . $path, null, stream_context_create(['http' => $http]));
            if ($response === false) {
                return null;
            } else {
                return json_decode($response, $getAssArray);
            }
        } catch (Exception $e) {
            echo "[" . date('c') . "] ElasticSearch Class Exception: " . $e->getMessage() . "\n";

            return null;
        }
    }

}

/**
 * show a status bar in the console
 *
 *
 * @param     $done
 * @param     $total
 * @param int $size
 */
function show_status($done, $total, $size = 50)
{
    static $start_time;
    // if we go over our bound, just ignore it
    if ($done > $total) {
        return;
    }
    if (empty($start_time)) {
        $start_time = time();
    }
    $now        = time();
    $perc       = (double) ($done / $total);
    $bar        = floor($perc * $size);
    $status_bar = "\r[";
    $status_bar .= str_repeat("=", $bar);
    if ($bar < $size) {
        $status_bar .= ">";
        $status_bar .= str_repeat(" ", $size - $bar);
    } else {
        $status_bar .= "=";
    }
    $disp       = number_format($perc * 100, 0);
    $status_bar .= "] $disp%  $done/$total";
    $rate       = ($now - $start_time) / $done;
    $left       = $total - $done;
    $eta        = round($rate * $left, 2);
    $elapsed    = $now - $start_time;
    $status_bar .= " remaining: " . number_format($eta) . " sec.  elapsed: " . number_format($elapsed) . " sec.";
    echo "$status_bar  ";
    flush();
    // when done, send a newline
    if ($done == $total) {
        echo "\n";
    }
}