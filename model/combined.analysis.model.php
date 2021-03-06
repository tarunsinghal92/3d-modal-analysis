<?php

/**
 * Combined Analysis Class
 *
 * @author Tarun K. Singhal <tarun.singhal@mail.utoronto.ca>
 *
 */

class CombinedAnalysis extends Common
{

    public function fetchResultsFromFiles($prefix)
    {
        // get files
        $files = glob("results/Pref".$prefix."_*.json");

        // fetch results for modal Analysis
        $res = [];
        $cnt = 0;
        foreach ($files as $key => $file) {
            if(strpos($file, 'Modal') > 0){
                $res['modal'] = json_decode(file_get_contents($file), true);
            }else{
                $step = explode('#',$file);
                $res['shear'][$cnt] = json_decode(file_get_contents($file), true);
                $cnt++;
            }
        }

        // get shear stress vs strain curve for center element of wall
        $file = fopen('results/shear_stress_vs_time_curve.txt','w+');
        foreach ($res['shear'] as $time => $data) {
            $s = ($time * .02);
            for ($i=0; $i < count($data); $i++) {
                $ele = intval(count($data[$i]['stresses']) / 2);
                $s .= ','. $data[$i]['stresses'][$ele][$ele][2];
            }
            $s .= PHP_EOL;
            fwrite($file, $s);
        }
        fclose($file);

        // get shear stress vs strain curve for center element of wall
        $file = fopen('results/shear_strain_vs_time_curve.txt','w+');
        foreach ($res['shear'] as $time => $data) {
            $s = ($time * .02);
            for ($i=0; $i < count($data); $i++) {
                $ele = intval(count($data[$i]['strains']) / 2);
                $s .= ','. $data[$i]['strains'][$ele][$ele][2];
            }
            $s .= PHP_EOL;
            fwrite($file, $s);
        }
        fclose($file);

        // save % cracked element floor wise
        $file = fopen('results/percent_cracked_vs_time_curve.txt','w+');
        foreach ($res['shear'] as $time => $data) {
            $s = ($time * .02);
            for ($i=0; $i < count($data); $i++) {
                $s .= ','. $data[$i]['total_cracked'];
            }
            $s .= PHP_EOL;
            fwrite($file, $s);
        }
        fclose($file);

        // return
        return $res;
    }

    public function getResults()
    {
        $prefix = rand(1, 100000);

        // run modal analysis
        $modal = new ModalAnalysis('data/el-centro-main.dat');
        $modal->run();
        $modal_results = $modal->getResults();
        $this->dump('Modal Analysis Done...', true);

        // store results in file
        $this->store_results_in_file($modal_results, "results/Pref" . $prefix . "_Res_Modal_All#" . time() . '.json');
        $this->dump('Modal File Write Done...', true);

        // run shear analysis for each time step and each floor
        foreach ($modal_results['displacement'] as $time => $floors) {
            $shear_results = [];
            foreach ($floors as $floor_id => $disp) {
                if($floor_id == 0){
                    $drift = [0, 0.001 * $disp]; //in mm
                }else{
                    $drift = [0.001 * $floors[$floor_id - 1], 0.001 * $disp];
                }
                $shear = new ShearWallAnalysis($drift, $floor_id);
                $shear->run();
                $shear_results[$floor_id] = $shear->getResults();
            }
            // store results in file
            $this->store_results_in_file($shear_results, "results/Pref" . $prefix . "_Res_Shear#$time#" . time() . '.json');
            $this->dump("Shear File Write Done for timestep => [$time]", true);
        }
        // debug
        $this->dump("Everything Done...[$prefix]", true);
        $this->dump("<a target=\"_blank\" href=\"http://127.0.0.1/3d-modal-analysis/postprocess/$prefix\">launch</a>", true);
    }
}

?>
