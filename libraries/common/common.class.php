<?php

/**
 * Common functions Class
 */

class Common{

  /**
   *
   * Pass a string through some filters for security purposes
   *
   * @param string $s The string to be secured
   * @return string The secured string
   *
   */
  public function secure_string($s){

      // Pass it through a list of string cleaning functions
      $s = htmlentities($s);
      $s = strip_tags($s);
      $s = utf8_decode($s);
      $s = htmlspecialchars($s);
      $s = stripslashes($s);
      $s = preg_replace( '/[^[:print:]]+/', '', trim($s)); //remove non printable characters

      // Trim to a certain length for security purposes
      $s = substr($s, 0, 100);

      //return
      return $s;
  }

  /**
   * zero array 2d
   */
  public function initialize_matrix($n)
  {
      $t = array_fill(0, $n, 0.0);
      return array_fill(0, $n, $t);
  }

  /**
  *
  * print full array
  */
  public function dump($data, $flag = false){
      if(DEBUG || $flag){
          echo "<pre>";
          print_r($data);
          echo "</pre>";
      }
  }

  /**
   * print matrix
   */
  public function show($matrix)
  {
      if(DEBUG){
          echo '<pre>';
          print($matrix);
          echo '</pre>';
      }
  }

  /**
   * [getParam description]
   * @param  [type] $param [description]
   * @return [type]        [description]
   */
  public function getParam($param){
    if(isset($this->$param)){
      return $this->$param;
    }
    return NULL;
  }

  function microtime_float()
  {
      list($usec, $sec) = explode(" ", microtime());
      return ((float)$usec + (float)$sec);
  }

  public function round_off($a, $n)
  {
      for ($i=0; $i < count($a); $i++) {
          $a[$i] = round($a[$i], $n);
      }
      return $a;
  }

  public function table($array)
  {
      $array = $array->map(function($x) {
          return round($x, 1);
      });
      $array = $array->getMatrix();
      print('<table>');
      for($i = 0; $i < count($array); $i++) {
          print('<tr>');
          for($ii = 0; $ii < count($array[$i]); $ii++) {
              print("<td>{$array[$i][$ii]}</td>");
          }
          print('</tr>');
      }
      print('</table>');
  }

}
