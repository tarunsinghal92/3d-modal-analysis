<?php

/**
 * Modal Analysis Class
 *
 * @TODO
 *  0. Newmark Implement Done!!!
 *  1. convert 2d to 3d (NOPES)
 *  2. add shearwalls
 *  3. interpolation function to get shear wall stresses done!!!
 *  4. UI
 *  5. Time step variation done!!!
 *  6. Non-linear Analysis done!!!
 *  7. Discuss whatever you have Done!!!
 *  8. Report/PPT/Flyer
 *
 *
 * @author Tarun K. Singhal <tarun.singhal@mail.utoronto.ca>
 *
 */

use MathPHP\LinearAlgebra\Matrix;
use MathPHP\LinearAlgebra\MatrixFactory;
use MathPHP\LinearAlgebra\Vector;

class ModalAnalysis extends Common
{

    private $massMatrix;
    private $dampingMatrix;
    private $stiffnessMatrix;
    private $influenceVector;
    private $spectralMatrix;
    private $modalMatrix;
    private $eqData;
    private $results;
    private $numFloors = 3;
    private $wallThickness = 0.1; // m
    private $massFloor = 3000; //kg
    private $floorWidth = 6; //m
    private $youngModulusMultI = 60000;  // KN m2
    private $heightColumn = 3; // in m
    private $damping = 0.05; // 5% in 1st and N-1th floors
    private $eqFile;
    private $timeStep = 0.02; //sec means half of 0.2 ie 0.1
    private $analysisLength = 1.0; //sec
    private $newMarkCons = [
      'alpha' => 0.5,
      'beta'  => 0.25
    ];

    public function __construct($eq)
    {
        $this->eqFile = $eq;
    }

    public function run()
    {

        //primilinary setup
        $this->makeInfluenceVector();
        $this->makeStiffnessMatrix();
        $this->makeMassMatrix();
        $this->doEigenValueAnalysis();
        $this->makeDampingMatrix();
        $this->getEarthquakeData();

        //run newmarks Analysis
        $this->runNewmarkAnalysis();

        //results
        $this->makePlotData();
        $this->makeCanvasData();

    }

    public function makeCanvasData()
    {
        $data = [];
        $FW = $this->floorWidth;
        $FH = $this->heightColumn;
        foreach ($this->results['displacement'] as $time => $floors) {
            $t = ['time'    => floatval($time),'floors'  => []];
            foreach ($floors as $floor => $disp) {
                $n1 = [((isset($floors[$floor - 1]) ? $floors[$floor - 1] : 0.0) * DISP_MAG) * OVERALL_MAG,($floor * $FH) * OVERALL_MAG];
                $n2 = [(($disp * DISP_MAG) + 0) * OVERALL_MAG,(($floor + 1) * $FH) * OVERALL_MAG];
                $n3 = [(($disp * DISP_MAG) + $FW) * OVERALL_MAG,(($floor + 1) * $FH) * OVERALL_MAG];
                $n4 = [((isset($floors[$floor - 1]) ? $floors[$floor - 1] : 0.0) * DISP_MAG + $FW) * OVERALL_MAG,($floor * $FH) * OVERALL_MAG];
                $t['floors'][$floor] = array(
                    [$n1, $n2],
                    [$n2, $n3],
                    [$n3, $n4],
                );
            }
            $data[] = $t;
        }
        $this->results['canvas'] = $data;
    }

    public function makePlotData()
    {
        //plot data
        $data = [];
        $cat = [];
        $ltime = 50000000;

        foreach ($this->results['eqdata'] as $time => $val) {
            if($time > $ltime) continue;
            $data[0]['name'] = 'El Centro ';
            $data[0]['data'][] = $val * 1;
        }

        foreach ($this->results['displacement'] as $time => $floor) {
            if($time > $ltime) continue;
            foreach ($floor as $id => $disp) {
                $data[$id + 1]['name'] = 'Floor ' . ($id + 1);
                $data[$id + 1]['data'][] = $disp * 1000;
            }
            $cat[] = $time;
        }
        $this->results['plot'] = ['legends' => $cat, 'data' => $data];
    }

    public function runNewmarkAnalysis()
    {
        //initial conditions
        $Xold = new Vector(array_fill(0, $this->numFloors, 0.0));
        $Vold = new Vector(array_fill(0, $this->numFloors, 0.0));
        $Aold = new Vector(array_fill(0, $this->numFloors, 0.0));
        $oldAg = 0.0;

        //global storage variable
        $results = [
          'eqdata'       => $this->eqData,
          'displacement' => [],
          'velocity'     => [],
          'acceleration' => [],
        ];

        //eq 4.346
        $kHat = $this->getKHat();

        foreach ($this->eqData as $time => $NewAg) {

            //eq 4.347
            $deltaP = $this->getDeltaP($Vold, $Aold, ($NewAg - $oldAg));

            //eq 4.345
            $deltaX = $kHat->inverse()->vectorMultiply($deltaP);

            //4.343
            $t1 = $Aold->scalarMultiply($this->timeStep);
            $t2 = $deltaX->scalarMultiply(($this->newMarkCons['alpha'] / ($this->newMarkCons['beta'] * $this->timeStep)));
            $t3 = $Vold->scalarMultiply($this->newMarkCons['alpha'] / $this->newMarkCons['beta']);
            $t4 = $Aold->scalarMultiply((($this->newMarkCons['alpha'] * $this->timeStep) / ($this->newMarkCons['beta'] * 2)));
            $deltaV = $t1->add($t2)->subtract($t3)->subtract($t4);

            //4.342
            $t1 = $Vold->scalarMultiply((1/($this->newMarkCons['beta'] * $this->timeStep)));
            $t2 = $Aold->scalarMultiply((1/($this->newMarkCons['beta'] * 2)));
            $t3 = $deltaX->scalarMultiply((1/($this->newMarkCons['beta'] * $this->timeStep)));
            $deltaA = $t3->subtract($t1)->subtract($t2);

            //eq 4.348
            $Xnew = $Xold->add($deltaX);
            $Vnew = $Vold->add($deltaV);
            $Anew = $Aold->add($deltaA);

            //eq 4.349
            $t1 = $this->massMatrix->vectorMultiply($this->influenceVector->scalarMultiply($NewAg))->scalarMultiply(-1);
            $t2 = $this->stiffnessMatrix->vectorMultiply($Xnew)->scalarMultiply(-1);
            $t3 = $this->dampingMatrix->vectorMultiply($Vnew)->scalarMultiply(-1);
            $Anew = $this->massMatrix->inverse()->vectorMultiply($t1->add($t2->add($t3)));

            //store data
            $results['displacement'][$time] = $Xnew->getVector();
            $results['velocity'][$time]     = $Vnew->getVector();
            $results['acceleration'][$time] = $Anew->getVector();

            //new becomes old
            $Xold = $Xnew;
            $Vold = $Vnew;
            $Aold = $Anew;
            $oldAg = $NewAg;
        }
        //store results
        $this->results = $results;

        //DEBUG
        $this->dump($this->results);
    }

    public function getKHat()
    {
        //see page 211 of EQ book
        $kHat1 = $this->stiffnessMatrix;
        $kHat2 = $this->massMatrix->scalarMultiply((1/(($this->timeStep * $this->timeStep)*$this->newMarkCons['beta'])));
        $kHat3 = $this->dampingMatrix->scalarMultiply(1/($this->newMarkCons['beta'] * $this->timeStep));
        $kHat = $kHat1->add($kHat2);
        return $kHat->add($kHat3);
    }

    //see page 211 of EQ book
    public function getDeltaP($vel, $acc, $deltaAg)
    {
        //first term @doubt here
        $p1 = $this->massMatrix->vectorMultiply($this->influenceVector->scalarMultiply($deltaAg))->scalarMultiply(-1);

        //second term
        $t1 = $vel->scalarMultiply((1/($this->newMarkCons['beta'] * $this->timeStep)));
        $t2 = $acc->scalarMultiply((1/($this->newMarkCons['beta'] * 2)));
        $p2 = $this->massMatrix->vectorMultiply($t1->add($t2));

        //third term
        $t1 = $vel->scalarMultiply((($this->newMarkCons['alpha'])/($this->newMarkCons['beta'])));
        $t2 = $acc->scalarMultiply((($this->newMarkCons['alpha'] - $this->newMarkCons['beta']*2) * $this->timeStep /($this->newMarkCons['beta'] * 2)));
        $p3 = $this->dampingMatrix->vectorMultiply($t1->add($t2));

        //sum up all
        return $p3->add($p2->add($p1));
    }

    public function makeInfluenceVector()
    {
        // @TODO change it for 3D - MDOF
        $this->influenceVector = new Vector(array_fill(0, $this->numFloors, 1.0));
    }

    public function getEarthquakeData()
    {
        $eq = [];
        $cnt = 0;
        $handle = fopen($this->eqFile, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                // process the line read.
                if($cnt != 0){
                    $eq[((preg_split('/\t/', $line))[0])] = floatval((preg_split('/\t/', $line)[1]));
                }else{
                    $eq['0.0'] = 0.0;
                }
                $cnt++;
            }
            fclose($handle);
        } else {
            // error opening the file.
            DIE('FILE ERROR!');
        }

        //add extra time step
        $lval = floatval(key( array_slice( $eq, -1, 1, TRUE )));
        for ($i=1; $i <= ($this->analysisLength / $this->timeStep); $i++) {
            $eq[strval($lval + ($i * $this->timeStep))] = 0.0;
        }
        $this->eqData = $eq;

        //DEBUG
        $this->dump($this->eqData, false);
    }

    public function doEigenValueAnalysis()
    {
        /**
         * Eigen Value Analysis happens here
         * |[K] - w2[M]| = 0
         */
         $mstr = [];
         foreach ($this->massMatrix->getMatrix() as $key => $value) {
            $mstr[] = implode(',', $value);
         }
         $kstr = [];
         foreach ($this->stiffnessMatrix->getMatrix() as $key => $value) {
            $kstr[] = implode(',', $value);
         }
         exec('python eigen.py "' . implode(';', $kstr) . '" "' . implode(';', $mstr) . '"', $out);

         //make spectralMatrix
         $specMatrix = $this->initialize_matrix($this->numFloors);
         foreach (explode(',', $out[0]) as $key => $value) {
             $specMatrix[$key][$key] = floatval($value);
         }
         $this->spectralMatrix = MatrixFactory::create($specMatrix);

         //modal matrix & normalize it
         $modalMatrix = $this->initialize_matrix($this->numFloors);
         $rcnt = -1;
         foreach (explode(',', $out[1]) as $key => $value) {
            if($key % $this->numFloors == 0) $rcnt++;
            $modalMatrix[$rcnt][($key % $this->numFloors)] = floatval($value) / explode(',', $out[1])[$key % $this->numFloors];
         }
         $this->modalMatrix = MatrixFactory::create($modalMatrix);

         //DEBUG
         $this->show($this->modalMatrix);
         $this->show($this->spectralMatrix);
    }

    public function makeDampingMatrix()
    {
        /**
         * Rayleigh's Damping Assumed
         * [C] = a[K] + b[M]
         */

        //find  a & b
        $w1 = sqrt($this->spectralMatrix->get(1,1));
        $w2 = sqrt($this->spectralMatrix->get($this->numFloors - 1, $this->numFloors - 1));
        $alpha = 2 * $w1 * $w2 * $this->damping / ($w1 + $w2);
        $beta = 2 * $this->damping / ($w1 + $w2);

        //multiple scalar values of a & b
        $k = $this->stiffnessMatrix->scalarMultiply($alpha);
        $m = $this->massMatrix->scalarMultiply($beta);

        //store
        $this->dampingMatrix = $k->add($m);

        //DEBUG
        $this->show($this->dampingMatrix);
    }

    public function makeStiffnessMatrix()
    {
        /**
         * @Assumption:
         *  1. Columns & shearwalls contribute only to stiffness
         *  2. Beams have infinite stiffness
         *  @TODO add shearwall stiffness
         *  K = 12EI/h3
         */

        //floor stiffness
        $k = 12 * $this->youngModulusMultI / ($this->heightColumn * $this->heightColumn * $this->heightColumn);

        //shearwall stuffness @http://ef.engr.utk.edu/ce576-2014-01/notes/Shear-Walls.pdf
        $r = $this->heightColumn / $this->floorWidth;
        $k += (ShearWallAnalysis::Ec * ShearWallAnalysis::$t) / ($r * ($r**2 + 3));

        //2DOF stiffness
        $stiffness2DOF = [
           [2 * $k  , -$k],
           [ -$k    , $k]
        ];

         //initialize matrix
        $stiffMatrix = $this->initialize_matrix($this->numFloors);

        //fill in data
        for ($i=0; $i < ($this->numFloors - 1); $i++) {
            $stiffMatrix[$i][$i] = $stiffness2DOF[0][0];
            $stiffMatrix[$i][$i+1] = $stiffness2DOF[0][1];
            $stiffMatrix[$i+1][$i] = $stiffness2DOF[1][0];
            $stiffMatrix[$i+1][$i+1] = $stiffness2DOF[1][1];
        }

        //store
        $this->stiffnessMatrix = MatrixFactory::create($stiffMatrix);

        //DEBUG
        $this->show($this->stiffnessMatrix);
    }

    public function makeMassMatrix()
    {
        /**
         * @Assumption:
         *  1. All floors have same mass
         *  2. Columns are massless and contribute only to stiffness
         *  3. ShearWalls are massless and contribute only to stiffness
         */

        //create an identity matrix
        $iMatrix = MatrixFactory::identity($this->numFloors);

        //multiple [I] by mass of each floor
        $this->massMatrix = $iMatrix->scalarMultiply($this->massFloor);

        //DEBUG
        $this->show($this->massMatrix);
    }

    public function getResults()
    {
        return $this->results;
    }

}

?>
