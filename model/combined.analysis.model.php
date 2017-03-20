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

        // return
        return $res;
    }

    public function getResults()
    {
        $prefix = rand(1, 100000);

        // run modal analysis
        $modal = new ModalAnalysis('data/el-centro-tiny.dat');
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
                    $drift = [0, $disp];
                }else{
                    $drift = [$floors[$floor_id - 1], $disp];
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
        $this->dump("Everything Done...", true);
    }
}

?>
