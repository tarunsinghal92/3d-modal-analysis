<?php

/**
 * Shear Wall Analysis Class using MCFT
 *
 * @author Tarun K. Singhal <tarun.singhal@mail.utoronto.ca>
 *
 */

use MathPHP\LinearAlgebra\Matrix;
use MathPHP\LinearAlgebra\MatrixFactory;
use MathPHP\LinearAlgebra\Vector;

class ShearWallAnalysis extends Common
{

    private $fc_dash = 21.8; // MPa
    private $ft_dash = 1.54; // MPa
    private $ec_dash = -1.80e-3; // dimensionless
    private $rho_x = 1; // %
    private $rho_y = 1; // %
    private $Ec = 24200; // MPa
    private $Es = 200000; // Mpa
    private $nu = 0.30; // Mpa
    private $fyx = 430; //Mpa
    private $length = ['a' => 3, 'b' => 6]; // in meters
    private $t = 0.10; // thickness meters
    private $num_elements = 8; // in each direction so 10*10 elements in total
    private $ele_d_mat = [];
    private $ele_k_mat = [];
    private $global_k_mat;
    private $num_iterations = 6;
    private $connectivity_list = [];
    private $displacements = [];
    private $forces = [];
    private $BIGNUM = 10e10;
    private $old_global_k_mat = [];
    private $final_stress = [];
    private $final_strain = [];


    public function __construct()
    {
        $dofs =  2 * (1 + $this->num_elements) * (1 + $this->num_elements);
        $this->global_k_mat = MatrixFactory::create($this->initialize_matrix($dofs));

    }

    public function run()
    {
        $ts = $this->microtime_float();

        for ($i = 1; $i < $this->num_iterations; $i++) {

            // for global stiffness matrix
            $this->old_global_k_mat = $this->global_k_mat;
            $this->getGlobalStiffness($i);

            // define displacements matrix
            $this->get_displacements();

            // define force matrix
            $this->get_forces();

            // solve matrix
            $this->solve_matrix();

            // check converge$this
            $this->check_convergence($this->old_global_k_mat, $i);
            ob_flush();flush();
        }

        // calculate material stress and strains
        $this->cal_final_stress_strain();

        if(DEBUG){
            // $this->table($this->global_k_mat);
            // $this->dump($this->forces);
            $this->dump($this->displacements);
            $this->dump($this->final_stress);
            $this->dump($this->final_strain);
        }
        die();
    }

    public function cal_final_stress_strain()
    {
      $stress = [];
      $strain = [];
      for ($i = 0; $i < $this->num_elements; $i++) {
          for ($j = 0; $j < $this->num_elements; $j++) {
              // calculate strains
              $clist = $this->connectivity_list[$i][$j];
              $disp = $this->displacements;
              $x_size = $this->length['a'] / $this->num_elements;
              $y_size = $this->length['b'] / $this->num_elements;
              $tnodes = ($this->num_elements + 1) * ($this->num_elements + 1);

              $ex = ($disp[$clist[1]] + $disp[$clist[2]] - $disp[$clist[0]] - $disp[$clist[3]]) / (2 * $x_size);
              $ey = ($disp[$tnodes + $clist[2]] + $disp[$tnodes + $clist[3]] - $disp[$tnodes + $clist[0]] - $disp[$tnodes + $clist[1]]) / (2 * $y_size);
              $Yxy = ($disp[$clist[2]] + $disp[$clist[3]] - $disp[$clist[0]] - $disp[$clist[1]]) / (2 * $y_size);
              $Yxy += ($disp[$tnodes + $clist[1]] + $disp[$tnodes + $clist[2]] - $disp[$tnodes + $clist[0]] - $disp[$tnodes + $clist[3]]) / (2 * $x_size);

              $ec1 = 0.5 * (($ex + $ey) + sqrt(($ex - $ey)**2 + $Yxy**2));
              $ec2 = 0.5 * (($ex + $ey) - sqrt(($ex - $ey)**2 + $Yxy**2));

              $stress[$i][$j] = $this->ele_d_mat[$i][$j]->multiply(new Vector([$ex, $ey, $Yxy]))->getColumn(0);
              $strain[$i][$j] = [$ex, $ey, $Yxy];
          }
       }
       $this->final_stress = $stress;
       $this->final_strain = $strain;
    }

    public function check_convergence($old, $iteration)
    {
        $diff = $old->subtract($this->global_k_mat)->map(function($x) {
            return abs($x);
        });
        $diff = $diff->getMatrix();
        $sum = 0;
        $total = count($diff) * count($diff);
        for ($i=0; $i < count($diff); $i++) {
            $sum += array_sum($diff[$i]);
        }
        $this->dump('iteration: [' . $iteration . ' => ' . ($sum / $total) . ']');
    }

    public function get_forces()
    {
        $nx = (1 + $this->num_elements);
        $tnodes = $nx * $nx;
        $disp = $this->displacements;
        $forces = array_fill(0, (2 * $tnodes), 'unknown');
        for ($i = 0; $i < count($disp); $i++) {
            if($disp[$i] === 'unknown'){
                $forces[$i] = 0.0;
            }
        }
        $this->forces = $forces;
    }

    public function solve_matrix()
    {

        // get variables
        $removelist = [];
        $cnt = 0;
        $disp = $this->displacements;
        $stiff = $this->global_k_mat;
        $forces = $this->forces;

        // remove dof which are useless
        for ($i = 0; $i < count($this->displacements); $i++) {
            if($disp[$i] == -1){
                $removelist[] = $i;
                unset($disp[$i]);
                unset($forces[$i]);
            }
        }
        $disp = (array_values($disp));
        $forces = (array_values($forces));
        for ($i = 0; $i < count($removelist); $i++) {
            $stiff = $stiff->rowExclude($removelist[$i] - $cnt);
            $stiff = $stiff->columnExclude($removelist[$i] - $cnt);
            $cnt++;
        }

        // form force matrix
        $this->payne_iron_solver($stiff->getMatrix(), $disp, $forces);

        // debug
        $this->displacements = $disp;
        $this->forces = $forces;

    }

    public function payne_iron_solver($a, &$x, &$b)
    {
        // set to big num
        $s = $a;
        $ts = $this->microtime_float();
        for ($i = 0; $i < count($b); $i++) {
            if($b[$i] === 'unknown'){
                $b[$i] = $a[$i][$i] * $x[$i] * $this->BIGNUM;
                $a[$i][$i] = $a[$i][$i] * $this->BIGNUM;
            }
        }
        $a = MatrixFactory::create($a);
        $s = MatrixFactory::create($s);
        $b = new Vector($b);

        // solve
        $x = $a->solve($b);
        $b = $s->multiply($x);
        $x = $x->getVector();
        $b = $b->getColumn(0);
    }

    public function get_displacements()
    {
        // empty array
        $ls = 1;
        $rs = 1;
        $nx = (1 + $this->num_elements);
        $tnodes = $nx * $nx;
        $disp = array_fill(0, (2 * $tnodes), 'unknown');

        // set disp
        for ($i = 0; $i < count($disp); $i++) {

            // set lower one to -1 i.e. fixed dofs {both x & y}
            if(0 <= $i && $i < $nx){
                $disp[$i] = 0;
                $disp[$i + $tnodes] = 0;
            }

            // set upper one to 0.1 i.e. some disp {only x}
            if(($nx * ($nx - 1)) <= $i && $i < ($nx * $nx)){
                $disp[$i] = 0.005;
                // set upper one to 0 i.e. roller case disp {only y}
                $disp[$i + $nx * $nx] = 0;
            }

            // set left side one to 0.1 by linearly variation i.e. some disp from 0.0 to 0.1 {only x}
            // if(($nx * $ls) == $i && $i < $tnodes){
            //     $disp[$i] = $ls * (0.005 / $this->num_elements);
            //     $disp[$i + $nx * $nx] = 0;
            //     $ls++;
            // }
            //
            // // set right side one to 0.1 by linearly variation i.e. some disp from 0.0 to 0.1 {only x}
            // if(($nx * ($rs + 1) - 1) == $i && $i < $tnodes){
            //     $disp[$i] = $rs * (0.005 / $this->num_elements);
            //     $disp[$i + $nx * $nx] = 0;
            //     $rs++;
            // }
        }

        // store
        $this->displacements = $disp;
    }

    public function getGlobalStiffness($iteration)
    {
        // get individual matrices
        $ele_d_mat = [];
        $ele_k_mat = [];
        $c_list    = [];
        for ($i = 0; $i < $this->num_elements; $i++) {
            for ($j = 0; $j < $this->num_elements; $j++) {

                // get stiffness
                $ele_d_mat[$i][$j] = $this->getElementWiseMeterialStiffness($iteration, $i, $j);
                $ele_k_mat[$i][$j] = $this->getElementWiseGlobalStiffness($ele_d_mat[$i][$j]);

                // store connectivity CCW
                $c_list[$i][$j] = [
                    (($this->num_elements + 1) * $j + $i),
                    (($this->num_elements + 1) * $j + $i + 1),
                    (($this->num_elements + 1) * ($j + 1) + $i + 1),
                    (($this->num_elements + 1) * ($j + 1) + $i)
                ];
            }
        }

        // form global matrix
        $dofs =  2 * (1 + $this->num_elements) * (1 + $this->num_elements);
        $global_k_mat = $this->initialize_matrix($dofs);

        //fill in data
        for ($i = 0; $i < $this->num_elements; $i++) {
            for ($j = 0; $j < $this->num_elements; $j++) {
                $d = $this->get_dof_list($c_list[$i][$j]);
                foreach ($d as $row => $dofx) {
                    foreach ($d as $col => $dofy) {
                        $global_k_mat[$dofx][$dofy] += $ele_k_mat[$i][$j][$row][$col];
                    }
                }
            }
        }

        //store it
        $this->ele_d_mat = $ele_d_mat;
        $this->ele_k_mat = $ele_k_mat;
        $this->connectivity_list = $c_list;
        $this->global_k_mat = MatrixFactory::create($global_k_mat);
    }

    public function get_dof_list($c_list)
    {
        $v = $c_list;
        $u = $c_list;
        $tnodes = ($this->num_elements + 1) * ($this->num_elements + 1);
        foreach ($v as $key => $value) {
            $v[$key] = $value + $tnodes;
        }
        return array_merge($u, $v);
    }

    public function getElementWiseMeterialStiffness($iteration, $i, $j)
    {
        // check stage type : linear or non linear
        $islinear = false;
        if($iteration == 1){
            $islinear = true;
        }else{
            // calculate strains
            $clist = $this->connectivity_list[$i][$j];
            $disp = $this->displacements;
            $x_size = $this->length['a'] / $this->num_elements;
            $y_size = $this->length['b'] / $this->num_elements;
            $tnodes = ($this->num_elements + 1) * ($this->num_elements + 1);

            $ex = ($disp[$clist[1]] + $disp[$clist[2]] - $disp[$clist[0]] - $disp[$clist[3]]) / (2 * $x_size);
            $ey = ($disp[$tnodes + $clist[2]] + $disp[$tnodes + $clist[3]] - $disp[$tnodes + $clist[0]] - $disp[$tnodes + $clist[1]]) / (2 * $y_size);
            $Yxy = ($disp[$clist[2]] + $disp[$clist[3]] - $disp[$clist[0]] - $disp[$clist[1]]) / (2 * $y_size);
            $Yxy += ($disp[$tnodes + $clist[1]] + $disp[$tnodes + $clist[2]] - $disp[$tnodes + $clist[0]] - $disp[$tnodes + $clist[3]]) / (2 * $x_size);

            $ec1 = 0.5 * (($ex + $ey) + sqrt(($ex - $ey)**2 + $Yxy**2));
            $ec2 = 0.5 * (($ex + $ey) - sqrt(($ex - $ey)**2 + $Yxy**2));
            $es1 = $ex;
            $es2 = $ey;
            if($ey - $ex != 0){
                $theta = 0.5 * atan($Yxy / ($ex - $ey)); // in radians @ check this
            }else{
                $theta = 0;
            }
            $strains = new Vector([$ex, $ey, $Yxy]);
            $stress = $this->ele_d_mat[$i][$j]->multiply($strains)->getColumn(0);
            // $this->show($this->ele_d_mat[$i][$j]);
            // $this->dump($stress);
            // $this->dump([$ex, $ey, $Yxy]);
            if($stress[0] >= $this->ft_dash){

                // fc*
                $fc1 = $this->ft_dash * (1 + sqrt(200 * $ec1));
                $beta = (0.85 - 0.34 * ($ec1 / $ec2))**-1;
                $ep = $beta * $this->ec_dash;
                $fp = $beta * $this->fc_dash;
                if(0 < $ec2 && $ec2 < $ep){
                    $fc2 = -$fp * (2 * ($ec2 / $ep) - ($ec2 / $ep)**2);
                }else if($ep < $ec2 && $ec2 < $this->ec_dash){
                    $fc2 = -$fp;
                }else{
                    $fc2 = -$fp * (2 * ($ec2 / $this->ec_dash) - ($ec2 / $this->ec_dash)**2);
                }
                $fs1 = min(($this->Es * $es1), $this->fyx);
                $fs2 = min(($this->Es * $es2), $this->fyx);
                $Es2 = ($es2 == 0) ? $this->Es : ($fs2/$es2);
                $Es1 = ($es1 == 0) ? $this->Es : ($fs1/$es1);

                // for concrete
                $c = $this->initialize_matrix(3);
                $c[0][0] = $fc1 / $ec1;
                $c[1][1] = $fc2 / $ec2;
                $c[2][2] = ($c[1][1] * $c[0][0]) / ($c[1][1] + $c[0][0]);
                $c = MatrixFactory::create($c);
                $c = $this->transform($c, $theta);

                // for reinf - x
                $rx = $this->initialize_matrix(3);
                $rx[0][0] = $this->rho_x * $Es1 / 100;
                $rx = MatrixFactory::create($rx);
                $rx = $this->transform($rx, deg2rad(0));

                // for reinf - y
                $ry = $this->initialize_matrix(3);
                $ry[0][0] = $this->rho_y * $Es2 / 100;
                $ry = MatrixFactory::create($ry);
                $ry = $this->transform($ry, deg2rad(90));

                // add all
                $matrix = $c->add($rx)->add($ry);
                $islinear = false;
            }else{
                $islinear = true;
            }
        }
        // print("($i, $j) => $islinear <br>");
        // based on above findings
        if($islinear){

            // initialize matrix
            $matrix = $this->initialize_matrix(3);
            $matrix[0][0] = 1;
            $matrix[1][1] = 1;
            $matrix[1][0] = $this->nu;
            $matrix[0][1] = $this->nu;
            $matrix[2][2] = (1 - $this->nu) / 2;
            $matrix = MatrixFactory::create($matrix);
            $matrix = $matrix->scalarMultiply($this->Ec / (1 - ($this->nu * $this->nu)));
        }

        // return
        return $matrix;
    }

    public function getElementWiseGlobalStiffness($dmatrix)
    {
        $x = $this->length['a'] / $this->num_elements;
        $y = $this->length['b'] / $this->num_elements;
        $t0 = $this->t/(12 * $x * $y);

        // initialize matrix
        $matrix = $this->initialize_matrix(8);
        $matrix[0][0] = 4 * $y * $y * $t0 * $dmatrix[0][0] + 6 * $x * $y * $t0 * $dmatrix[0][2] + 4 * $x * $x * $t0 * $dmatrix[2][2];
        $matrix[0][1] = -4 * $y * $y * $t0 * $dmatrix[0][0] + 2 * $x * $x * $t0 * $dmatrix[2][2];
        $matrix[0][2] = -2 * $y * $y * $t0 * $dmatrix[0][0] - 6 * $x * $y * $t0 * $dmatrix[0][2] - 2 * $x * $x * $t0 * $dmatrix[2][2];
        $matrix[0][3] = 2 * $y * $y * $t0 * $dmatrix[0][0] - 4 * $x * $x * $t0 * $dmatrix[2][2];
        $matrix[0][4] = 3 * $y * $x * $t0 * $dmatrix[0][1] + 4 * $y * $y * $t0 * $dmatrix[0][2] + 4 * $x * $x * $t0 * $dmatrix[1][2] + 3 * $x * $y * $t0 * $dmatrix[2][2];
        $matrix[0][5] = 3 * $y * $x * $t0 * $dmatrix[0][1] - 4 * $y * $y * $t0 * $dmatrix[0][2] + 2 * $x * $x * $t0 * $dmatrix[1][2] - 3 * $x * $y * $t0 * $dmatrix[2][2];
        $matrix[0][6] = -3 * $y * $x * $t0 * $dmatrix[0][1] - 2 * $y * $y * $t0 * $dmatrix[0][2] - 2 * $x * $x * $t0 * $dmatrix[1][2] - 3 * $x * $y * $t0 * $dmatrix[2][2];
        $matrix[0][7] = -3 * $y * $x * $t0 * $dmatrix[0][1] + 2 * $y * $y * $t0 * $dmatrix[0][2] - 4 * $x * $x * $t0 * $dmatrix[1][2] + 3 * $x * $y * $t0 * $dmatrix[2][2];
        $matrix[1][0] = $matrix[0][1];
        $matrix[1][1] = 4 * $y * $y * $t0 * $dmatrix[0][0] - 6 * $x * $y * $t0 * $dmatrix[0][2] + 4 * $x * $x * $t0 * $dmatrix[2][2];
        $matrix[1][2] = $matrix[0][3];
        $matrix[1][3] = -2 * $y * $y * $t0 * $dmatrix[0][0] + 6 * $x * $y * $t0 * $dmatrix[0][2] - 2 * $x * $x * $t0 * $dmatrix[2][2];
        $matrix[1][4] = -3 * $y * $x * $t0 * $dmatrix[0][1] - 4 * $y * $y * $t0 * $dmatrix[0][2] + 2 * $x * $x * $t0 * $dmatrix[1][2] + 3 * $x * $y * $t0 * $dmatrix[2][2];
        $matrix[1][5] = -3 * $y * $x * $t0 * $dmatrix[0][1] + 4 * $y * $y * $t0 * $dmatrix[0][2] + 4 * $x * $x * $t0 * $dmatrix[1][2] - 3 * $x * $y * $t0 * $dmatrix[2][2];
        $matrix[1][6] = 3 * $y * $x * $t0 * $dmatrix[0][1] + 2 * $y * $y * $t0 * $dmatrix[0][2] - 4 * $x * $x * $t0 * $dmatrix[1][2] - 3 * $x * $y * $t0 * $dmatrix[2][2];
        $matrix[1][7] = 3 * $y * $x * $t0 * $dmatrix[0][1] - 2 * $y * $y * $t0 * $dmatrix[0][2] - 2 * $x * $x * $t0 * $dmatrix[1][2] + 3 * $x * $y * $t0 * $dmatrix[2][2];
        $matrix[2][0] = $matrix[0][2];
        $matrix[2][1] = $matrix[1][2];
        $matrix[2][2] = $matrix[0][0];
        $matrix[2][3] = $matrix[0][1];
        $matrix[2][4] = $matrix[0][6];
        $matrix[2][5] = $matrix[0][7];
        $matrix[2][6] = $matrix[0][4];
        $matrix[2][7] = $matrix[0][5];
        $matrix[3][0] = $matrix[0][3];
        $matrix[3][1] = $matrix[1][3];
        $matrix[3][2] = $matrix[2][3];
        $matrix[3][3] = $matrix[1][1];
        $matrix[3][4] = $matrix[1][6];
        $matrix[3][5] = $matrix[1][7];
        $matrix[3][6] = $matrix[1][4];
        $matrix[3][7] = $matrix[1][5];
        $matrix[4][0] = $matrix[0][4];
        $matrix[4][1] = $matrix[1][4];
        $matrix[4][2] = $matrix[2][4];
        $matrix[4][3] = $matrix[3][4];
        $matrix[4][4] = 4 * $x * $x * $t0 * $dmatrix[1][1] + 6 * $y * $x * $t0 * $dmatrix[1][2] + 4 * $y * $y * $t0 * $dmatrix[2][2];
        $matrix[4][5] = 2 * $x * $x * $t0 * $dmatrix[1][1] - 4 * $y * $y * $t0 * $dmatrix[2][2];
        $matrix[4][6] = -2 * $x * $x * $t0 * $dmatrix[1][1] - 6 * $y * $x * $t0 * $dmatrix[1][2] - 2 * $y * $y * $t0 * $dmatrix[2][2];
        $matrix[4][7] = -4 * $x * $x * $t0 * $dmatrix[1][1] + 2 * $y * $y * $t0 * $dmatrix[2][2];
        $matrix[5][0] = $matrix[0][5];
        $matrix[5][1] = $matrix[1][5];
        $matrix[5][2] = $matrix[2][5];
        $matrix[5][3] = $matrix[3][5];
        $matrix[5][4] = $matrix[4][5];
        $matrix[5][5] = 4 * $x * $x * $t0 * $dmatrix[1][1] - 6 * $y * $x * $t0 * $dmatrix[1][2] + 4 * $y * $y * $t0 * $dmatrix[2][2];
        $matrix[5][6] = $matrix[4][7];
        $matrix[5][7] = -2 * $x * $x * $t0 * $dmatrix[1][1] + 6 * $y * $x * $t0 * $dmatrix[1][2] - 2 * $y * $y * $t0 * $dmatrix[2][2];
        $matrix[6][0] = $matrix[0][6];
        $matrix[6][1] = $matrix[1][6];
        $matrix[6][2] = $matrix[2][6];
        $matrix[6][3] = $matrix[3][6];
        $matrix[6][4] = $matrix[4][6];
        $matrix[6][5] = $matrix[5][6];
        $matrix[6][6] = $matrix[4][4];
        $matrix[6][7] = $matrix[4][5];
        $matrix[7][0] = $matrix[0][7];
        $matrix[7][1] = $matrix[1][7];
        $matrix[7][2] = $matrix[2][7];
        $matrix[7][3] = $matrix[3][7];
        $matrix[7][4] = $matrix[4][7];
        $matrix[7][5] = $matrix[5][7];
        $matrix[7][6] = $matrix[6][7];
        $matrix[7][7] = $matrix[5][5];


        // return matrix
        return MatrixFactory::create($matrix);
    }

    public function transform($mat, $theta) //rad
    {
        $t = $this->initialize_matrix(3);
        $t[0][0] = cos($theta) * cos($theta);
        $t[0][1] = sin($theta) * sin($theta);
        $t[0][2] = sin($theta) * cos($theta);
        $t[1][0] = sin($theta) * sin($theta);
        $t[1][1] = cos($theta) * cos($theta);
        $t[1][2] = -1 * cos($theta) * cos($theta);
        $t[2][0] = -2 * sin($theta) * cos($theta);
        $t[2][1] = +2 * cos($theta) * cos($theta);
        $t[2][2] = cos($theta) * cos($theta) - sin($theta) * sin($theta);
        $t = MatrixFactory::create($t);

        // return transfrmed matrix
        return $t->transpose()->multiply($mat)->multiply($t);
    }

}
