<?php
/**
 * ApiController
 *
 * Controller for backend calls to retrieve data, etc.
 *
 * Author: Mark Troyer <disco@box.com>
 * Date Created: 02 June 2013
 *
 * @package StatusWolf.Controller
 */


class ApiController extends SWController
{

  public function __construct($url_path)
  {

    if(SWConfig::read_values('statuswolf.debug'))
    {
      $this->loggy = new KLogger(ROOT . 'app/log/', KLogger::DEBUG);
    }
    else
    {
      $this->loggy = new KLogger(ROOT . 'app/log/', KLogger::INFO);
    }
    $this->log_tag = '(' . $_SESSION['_sw_authsession']['username'] . '|' . $_SESSION['_sw_authsession']['sessionip'] . ') ';

    parent::__construct();
    ini_set('max_input_vars', 5000);
    if (!empty($url_path[0]))
    {
      $_api_function = array_shift($url_path);
      $this->$_api_function($url_path);
    }
    else
    {
      throw new SWException('No API function found');
    }
  }

  protected function datasource_form($form)
  {
    if (!empty($form) && $form[0])
    {
      ob_start();
      include VIEWS . $form[0] . '.php';
      $raw_form = ob_get_contents();
      $form_data = array('form_source' => $raw_form);
      ob_end_clean();
      echo json_encode($form_data);
    }
    else
    {
      throw new SWException('No datasource form found');
    }
  }

  protected function tsdb_metric_list($query_bits) {
    list($q, $query) = explode('=', $query_bits[0]);
    $query_url = 'http://opentsdb.ve.box.net:4242/suggest?type=metrics&q=';
    $curl = new Curl($query_url . $query);
    $ret = json_decode($curl->request());
    $data = array();
    $data['query'] = $query;
    if (count($ret) > 20) {
      $ret = array_slice($ret, 0, 20);
    }
    $data['suggestions'] = $ret;
    echo json_encode($data);
  }

  protected function opentsdb_anomaly_model($path)
  {
    $query_bits = $_POST;

    $build_start = time();
    $anomaly = new OpenTSDBAnomalyModel();
    $anomaly->generate($query_bits);
    $anomaly_data = $anomaly->get_cache_file();

    $this->loggy->logDebug($this->log_tag . 'Anomaly model build complete, returning cache file location:');
    $this->loggy->logDebug($this->log_tag . $anomaly_data);
    $build_end = time();
    $build_time = $build_end - $build_start;
    $this->loggy->logInfo($this->log_tag . "Total execution time for anomaly build: $build_time seconds");

    echo json_encode($anomaly_data);

  }

  protected function time_series_projection($path)
  {
    $data = $_POST;

    $projection_start = time();
    $series = $data['key'];
    if (file_exists($data['model_cache']))
    {
      $this->loggy->logDebug($this->log_tag . 'Loading cached model data');
      $model_data = file_get_contents($data['model_cache']);
      $model_data = unserialize($model_data);
      $data['model'] = $model_data['model'];
      unset($model_data);
    }
    else
    {
      $this->loggy->logCrit($this->log_tag . 'No cached model data found');
      return null;
    }
    if (file_exists($data['query_cache']))
    {
      $this->loggy->logDebug($this->log_tag . 'Loading current data');
      $current_data = file_get_contents($data['query_cache']);
      $current_data = unserialize($current_data);
      $data['actual'] = $current_data[$series];
    }
    else
    {
      $this->loggy->logDebug($this->log_tag . 'No query cache data found');
      return null;
    }

    $this->loggy->logDebug($this->log_tag . 'Building projection');

    $anomaly_graph = new TimeSeriesProjection();
    $anomaly_graph->build_series($data['actual'], $data['model']);
    $anomaly_data = array('projection' => $anomaly_graph->read());

    $this->loggy->logDebug($this->log_tag . 'Detecting anomolies in current metric data');

    $anomaly_finder = new DetectTimeSeriesAnomaly();
    $anomaly_data['anomalies'] = array();
    $anomaly_data['anomalies'] = $anomaly_finder->detect_anomaly($anomaly_data['projection'], $anomaly_graph->get_accuracy_margin());
    $projection_end = time();
    $projection_time = $projection_end - $projection_start;
    $this->loggy->logInfo($this->log_tag . "Projection and anomaly detection complete, total execution time: $projection_time seconds");

    echo json_encode($anomaly_data);
  }

}