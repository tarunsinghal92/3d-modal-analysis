<?php

/**
 * Main Controller Class
 *
 * @author Tarun K. Singhal <tarun.singhal@mail.utoronto.ca>
 *
 */

class MainController extends Common{

    /**
     * [$results description]
     * @var [type]
     */
     private $results;
     private $results2;

    /**
     * [$module description]
     * @var [type]
     */
    private $module;

    /**
     * [__construct description]
     * @param [type] $module [description]
     */
    public function __construct($module){

        //define var
        $this->module = $module;
    }

    /**
     * [run_analysis description]
     * @return boolean [description]
     */
    public function run_analysis()
    {
        $obj = new ModalAnalysis();
        $obj->run();
        $this->results = $obj->getResults();
    }

    /**
     * [run_shear_analysis description]
     * @return boolean [description]
     */
    public function run_shear_analysis()
    {
        $obj = new ShearWallAnalysis();
        $obj->run();
        $this->results2 = $obj->getResults();
    }

    /**
     * [show_template description]
     * @return [type] [description]
     */
    public function show_template(){

        //include head scripts
        require_once 'views/header.phtml';

        //show template
        require_once 'views/' . $this->module . '.phtml';

        //include footer
        require_once 'views/footer.phtml';
    }
}

?>
