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
    private $ec_dash = -0.0018; // dimensionless
    private $rho_x = 3.0; // %
    private $rho_y = 1.0; // %
    public  $Ec = 24200; // MPa
    private $Es = 200000; // Mpa
    private $nu = 0.30; // Mpa
    private $fyx = 430; //Mpa
    private $length = ['a' => 6.0, 'b' => 3.0]; // in meters (x,y)
    public  $t = 0.10; // thickness meters
    private $num_elements = 12; // in each direction so 10*10 elements in total
    private $ele_d_mat = [];
    private $ele_dc_mat = [];
    private $ele_ds_mat = [];
    private $ele_k_mat = [];
    private $global_k_mat;
    private $num_iterations = 10;
    private $connectivity_list = [];
    private $displacements = [];
    private $forces = [];
    private $BIGNUM = 10e10;
    private $old_global_k_mat = [];
    private $final_stress = [];
    private $final_strain = [];
    private $given_disp = []; // [lower, upper]
    private $floor_id;
    private $Sm = 50.0; //mm Smx, Smy pg 93
    private $SNUM = 10**-9;
    private $iscracked = [];
    private $legend = [];


    public function __construct($given_disp = [0, 0.005], $floor_id = 0)
    {
        $dofs =  2 * (1 + $this->num_elements) * (1 + $this->num_elements);
        $this->global_k_mat = MatrixFactory::create($this->initialize_matrix($dofs));
        $this->given_disp = $given_disp;
        $this->floor_id = $floor_id;
        $this->displacements = array_fill(0, $dofs, 0);
        $this->iscracked = $this->initialize_matrix($this->num_elements);

        for ($i = 0; $i < $this->num_elements; $i++) {
            for ($j = 0; $j < $this->num_elements; $j++) {

                // store connectivity CCW
                $this->connectivity_list[$i][$j] = [
                    (($this->num_elements + 1) * $j + $i),
                    (($this->num_elements + 1) * $j + $i + 1),
                    (($this->num_elements + 1) * ($j + 1) + $i + 1),
                    (($this->num_elements + 1) * ($j + 1) + $i)
                ];
            }
        }
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
            $check = $this->check_convergence($this->old_global_k_mat, $i);
            if($check < TOLERANCE){
                break;
            }
        }

        // calculate material stress and strains
        $this->cal_final_stress_strain();

        if(DEBUG){
            // $this->table($this->global_k_mat);
            // $this->dump($this->forces, true);
            // $this->dump($this->ele_d_mat, true);
            // $this->dump($this->final_strain, true);
            // $this->dump($this->displacements, true);
            // $this->dump($this->final_stress, true);
            // $this->dump($this->final_strain, true);
        }
        // $this->dump($this->ele_dc_mat, true);
        // die;
    }

    public function getResults()
    {
        $elements = [];
        $f_s = $this->sign($this->floor_id);
        $tn = ($this->num_elements + 1) * ($this->num_elements + 1);
        $x_size = $this->length['a'] / $this->num_elements;
        $y_size = $this->length['b'] / $this->num_elements;
        $disp = $this->displacements;
        for ($i = 0; $i < $this->num_elements; $i++) {
            for ($j = 0; $j < $this->num_elements; $j++) {
                $list = $this->get_dof_list($this->connectivity_list[$i][$j]);
                $elements[$i][$j][] = [OVERALL_MAG * (($f_s * $this->given_disp[0] + $disp[$list[0]]) * DISP_MAG + ($x_size * ($i + 0))) , OVERALL_MAG * ($disp[$list[0] + $tn] * DISP_MAG + ($this->length['b'] * $this->floor_id + $y_size * ($j + 0)))];
                $elements[$i][$j][] = [OVERALL_MAG * (($f_s * $this->given_disp[0] + $disp[$list[1]]) * DISP_MAG + ($x_size * ($i + 1))) , OVERALL_MAG * ($disp[$list[1] + $tn] * DISP_MAG + ($this->length['b'] * $this->floor_id + $y_size * ($j + 0)))];
                $elements[$i][$j][] = [OVERALL_MAG * (($f_s * $this->given_disp[0] + $disp[$list[2]]) * DISP_MAG + ($x_size * ($i + 1))) , OVERALL_MAG * ($disp[$list[2] + $tn] * DISP_MAG + ($this->length['b'] * $this->floor_id + $y_size * ($j + 1)))];
                $elements[$i][$j][] = [OVERALL_MAG * (($f_s * $this->given_disp[0] + $disp[$list[3]]) * DISP_MAG + ($x_size * ($i + 0))) , OVERALL_MAG * ($disp[$list[3] + $tn] * DISP_MAG + ($this->length['b'] * $this->floor_id + $y_size * ($j + 1)))];
            }
        }

        // add crack lines
        $tnodes = $tn;
        for ($i = 0; $i < $this->num_elements; $i++) {
            for ($j = 0; $j < $this->num_elements; $j++) {

                $centerx = ($elements[$i][$j][0][0] + $elements[$i][$j][1][0] + $elements[$i][$j][2][0] + $elements[$i][$j][3][0]) / 4;
                $centery = ($elements[$i][$j][0][1] + $elements[$i][$j][1][1] + $elements[$i][$j][2][1] + $elements[$i][$j][3][1]) / 4;
                $length = OVERALL_MAG * 0.5 * $y_size;
                $angle = $this->final_cracks[$i][$j];
                list($ex, $ey, $Yxy) = $this->get_principle_strains($i, $j);
                $ec1 = 0.5 * (($ex + $ey) + sqrt(($ex - $ey)**2 + $Yxy**2));

                $this->final_cracks[$i][$j] = [
                  'iscracked'  => (intval(abs(rad2deg($angle))) == 0 ? false : true),
                  'theta'      => rad2deg($angle),
                  'width'      => ($this->Sm * $ec1 / (cos($angle) + sin($angle))),
                  'pos1'       => [($centerx + $length * cos($angle)) , ($centery + $length * sin($angle))],
                  'pos2'       => [($centerx + $length * cos(pi() + $angle)) , ($centery + $length * sin(pi() + $angle))],
                ];
            }
        }

        $res = [
          'connectivity' => $this->connectivity_list,
          'displacements' => $this->displacements,
          'elements' => $elements,
          'cracks' => $this->final_cracks,
          'stresses' => $this->final_stress,
          'strains' => $this->final_strain,
          'legend' => $this->legend
        ];

        return $res;
    }

    public function cal_final_stress_strain()
    {
      $stress = [];
      $strain = [];
      $cracks = [];
      $legend['stress'][0] = ['min' => 1000000000, 'max' => -1000000000];
      $legend['stress'][1] = ['min' => 1000000000, 'max' => -1000000000];
      $legend['stress'][2] = ['min' => 1000000000, 'max' => -1000000000];
      $legend['strain'][0] = ['min' => 1000000000, 'max' => -1000000000];
      $legend['strain'][1] = ['min' => 1000000000, 'max' => -1000000000];
      $legend['strain'][2] = ['min' => 1000000000, 'max' => -1000000000];
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
              $cracks[$i][$j] = $this->calculate_theta($ex, $ey, $Yxy, true);

              //store max
              $legend['strain'][0]['max'] = max($strain[$i][$j][0], $legend['strain'][0]['max']);
              $legend['strain'][0]['min'] = min($strain[$i][$j][0], $legend['strain'][0]['min']);
              $legend['strain'][1]['max'] = max($strain[$i][$j][1], $legend['strain'][1]['max']);
              $legend['strain'][1]['min'] = min($strain[$i][$j][1], $legend['strain'][1]['min']);
              $legend['strain'][2]['max'] = max($strain[$i][$j][2], $legend['strain'][2]['max']);
              $legend['strain'][2]['min'] = min($strain[$i][$j][2], $legend['strain'][2]['min']);
              $legend['stress'][0]['min'] = min($stress[$i][$j][0], $legend['stress'][0]['min']);
              $legend['stress'][0]['max'] = max($stress[$i][$j][0], $legend['stress'][0]['max']);
              $legend['stress'][1]['min'] = min($stress[$i][$j][1], $legend['stress'][1]['min']);
              $legend['stress'][1]['max'] = max($stress[$i][$j][1], $legend['stress'][1]['max']);
              $legend['stress'][2]['min'] = min($stress[$i][$j][2], $legend['stress'][2]['min']);
              $legend['stress'][2]['max'] = max($stress[$i][$j][2], $legend['stress'][2]['max']);
          }
       }
       $this->final_stress = $stress;
       $this->final_strain = $strain;
       $this->final_cracks = $cracks;
       $this->legend = $legend;
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
        $this->dump('iteration: [' . $iteration . ' => ' . ($sum / $total) . ']', false);
        return $sum / $total;
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

    public function get_displacements()
    {
        // empty array
        $given_disp = $this->given_disp;
        $ls = 1;
        $rs = 1;
        $nx = (1 + $this->num_elements);
        $step =  $this->length['b'] / $this->num_elements;
        $tnodes = $nx * $nx;
        $disp = array_fill(0, (2 * $tnodes), 'unknown');

        // set disp
        for ($i = 0; $i < count($disp); $i++) {

            // set lower one to -1 i.e. fixed dofs {both x & y}
            if(0 <= $i && $i < $nx){
                $disp[$i] = -1;
                $disp[$i + $tnodes] = -1;
            }

            // set upper one to 0.1 i.e. some disp {only x}
            if(($nx * ($nx - 1)) <= $i && $i < ($nx * $nx)){
                $disp[$i] = $given_disp[1] - $given_disp[0];
                // set upper one to 0 i.e. roller case disp {only y}
                $disp[$i + $tnodes] = -1;
            }

            // set left side one to 0.1 by linearly variation i.e. some disp from 0.0 to 0.1 {only x}
            if(($nx * $ls) == $i && $i < $tnodes){
                $disp[$i] = (3.0 * ($given_disp[1] - $given_disp[0]) / $this->length['b']**2) * (1 - (2 * $ls * $step)/(3 * $this->length['b'])) * (($ls * $step)**2);
                // $disp[$i + $tnodes] = 0; // NOPES
                $ls++;
            }

            // set right side one to 0.1 by linearly variation i.e. some disp from 0.0 to 0.1 {only x}
            if(($nx * ($rs + 1) - 1) == $i && $i < $tnodes){
                $disp[$i] = (3.0 * ($given_disp[1] - $given_disp[0]) / $this->length['b']**2) * (1 - (2 * $rs * $step)/(3 * $this->length['b'])) * (($rs * $step)**2);
                // $disp[$i + $tnodes] = 0; // NOPES
                $rs++;
            }
        }

        // store
        $this->displacements = $disp;
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

        //restore dofs
        $cnt = 0;
        foreach ($this->displacements as $key => $value) {
            if(in_array($key, $removelist)){
                $this->displacements[$key] = 0;
            }else{
                $this->displacements[$key] = $disp[$cnt];
                $cnt++;
            }
        }

        // store
        $this->forces = $this->global_k_mat->multiply(new Vector($this->displacements))->getColumn(0);
    }

    public function payne_iron_solver($a, &$x, $b)
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
        $s = MatrixFactory::create($s);

        // solve
        $x = new Vector($this->gauss($a, $b)); // this is much faster
        $x = $x->getVector();
    }

    public function getGlobalStiffness($iteration)
    {
        // get individual matrices
        $ele_d_mat = [];
        $ele_k_mat = [];
        $c_list    = $this->connectivity_list;
        for ($i = 0; $i < $this->num_elements; $i++) {
            for ($j = 0; $j < $this->num_elements; $j++) {

                // get stiffness
                $ele_d_mat[$i][$j] = $this->getElementWiseMeterialStiffness($iteration, $i, $j);
                $ele_k_mat[$i][$j] = $this->getElementWiseGlobalStiffness($ele_d_mat[$i][$j]);
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

    public function get_principle_strains($i, $j)
    {
        $clist = $this->connectivity_list[$i][$j];
        $disp = $this->displacements;
        $x_size = $this->length['a'] / $this->num_elements;
        $y_size = $this->length['b'] / $this->num_elements;
        $tnodes = ($this->num_elements + 1) * ($this->num_elements + 1);

        // calculate ex, ey & Yxy
        $ex = ($disp[$clist[1]] + $disp[$clist[2]] - $disp[$clist[0]] - $disp[$clist[3]]) / (2 * $x_size);
        $ey = ($disp[$tnodes + $clist[2]] + $disp[$tnodes + $clist[3]] - $disp[$tnodes + $clist[0]] - $disp[$tnodes + $clist[1]]) / (2 * $y_size);
        $Yxy = ($disp[$clist[2]] + $disp[$clist[3]] - $disp[$clist[0]] - $disp[$clist[1]]) / (2 * $y_size);
        $Yxy += ($disp[$tnodes + $clist[1]] + $disp[$tnodes + $clist[2]] - $disp[$tnodes + $clist[0]] - $disp[$tnodes + $clist[3]]) / (2 * $x_size);

        // zero check
        if(abs($ex) < $this->SNUM)$ex = -$this->SNUM;
        if(abs($ey) < $this->SNUM)$ey = -$this->SNUM;

        // return
        return [$ex, $ey, $Yxy];
    }

    public function check_if_cracked($iscrack, $i, $j)
    {
        if($iscrack === 1) {
            return 1;
        }else{

            list($ex, $ey, $Yxy) = $this->get_principle_strains($i, $j);
            $c = $this->initialize_matrix(3);
            $c[0][0] = 1;
            $c[1][1] = 1;
            $c[1][0] = $this->nu;
            $c[0][1] = $this->nu;
            $c[2][2] = (1 - $this->nu) / 2;
            $c = MatrixFactory::create($c);
            $c = $c->scalarMultiply($this->Ec / (1 - ($this->nu * $this->nu)));
            $r = $c->multiply(new Vector([$ex, $ey, $Yxy]))->getColumn(0);
            $fc1 = 0.5 * (($r[0] + $r[1]) + sqrt(($r[0] - $r[1])**2 + 4*($r[2])**2));
            if(abs($fc1) >= $this->ft_dash){
                return 1;
            }
            return 0;
        }
    }

    public function getElementWiseMeterialStiffness($iteration, $i, $j)
    {
        // modifiy value
        $this->iscracked[$i][$j] = $this->check_if_cracked($this->iscracked[$i][$j], $i, $j);

        // check stage type : linear or non linear
        if($this->iscracked[$i][$j] === 0 || true){

            // for concrete
            $c = $this->initialize_matrix(3);
            $c[0][0] = 1;
            $c[1][1] = 1;
            $c[1][0] = $this->nu;
            $c[0][1] = $this->nu;
            $c[2][2] = (1 - $this->nu) / 2;
            $c = MatrixFactory::create($c);
            $this->ele_dc_mat[$i][$j] = $c;
            $c = $c->scalarMultiply($this->Ec / (1 - ($this->nu * $this->nu)));
            $matrix = $c;

            // for reinf - x
            $rx = $this->initialize_matrix(3);
            $rx[0][0] = $this->rho_x * $this->Es / 100;
            $rx = MatrixFactory::create($rx);
            $rx = $this->transform($rx, 0);

            // for reinf - y
            $ry = $this->initialize_matrix(3);
            $ry[0][0] = $this->rho_y * $this->Es / 100;
            $ry = MatrixFactory::create($ry);
            $ry = $this->transform($ry, PI()/2);

            // add all
            $matrix = $c->add($rx)->add($ry);

        }else{

            // calculate strains
            list($ex, $ey, $Yxy) = $this->get_principle_strains($i, $j);
            $ec1 = 0.5 * (($ex + $ey) + sqrt(($ex - $ey)**2 + $Yxy**2));
            $ec2 = 0.5 * (($ex + $ey) - sqrt(($ex - $ey)**2 + $Yxy**2));
            $es1 = $ex;
            $es2 = $ey;
            $theta = $this->calculate_theta($ex, $ey, $Yxy);

            // check yeilding across check
            $fc1 = $this->ft_dash * (1 + sqrt(200 * abs($ec1)));
            $fsx = min(abs($this->Es * $ex), $this->fyx);
            $fsy = min(abs($this->Es * $ey), $this->fyx);
            $fc1_star = 0.01 * $this->rho_x * ($this->fyx - $fsx) * cos($theta - 0)**2;
            $fc1_star += 0.01 * $this->rho_y * ($this->fyx - $fsy) * cos($theta - PI()/2)**2;
            $fc1 = min($fc1, $fc1_star);

            // do non linear analysis
            $fc2 = $this->get_fc2($ec1, $ec2);

            $fs1 = min(($this->Es * $es1), $this->fyx);
            $fs2 = min(($this->Es * $es2), $this->fyx);
            $Es2 = ($es2 == 0) ? $this->Es : min($this->Es, $fs2/$es2);
            $Es1 = ($es1 == 0) ? $this->Es : min($this->Es, $fs1/$es1);

            // for concrete
            $c = $this->initialize_matrix(3);
            $c[0][0] = $fc1 / $ec1;
            $c[1][1] = $fc2 / $ec2;
            $c[2][2] = ($c[1][1] * $c[0][0]) / ($c[1][1] + $c[0][0]);
            $c = MatrixFactory::create($c);
            $this->ele_dc_mat[$i][$j] = $c;
            $c = $this->transform($c, $theta);

            // for reinf - x
            $rx = $this->initialize_matrix(3);
            $rx[0][0] = $this->rho_x * $Es1 / 100;
            $rx = MatrixFactory::create($rx);
            $rx = $this->transform($rx, 0);

            // for reinf - y
            $ry = $this->initialize_matrix(3);
            $ry[0][0] = $this->rho_y * $Es2 / 100;
            $ry = MatrixFactory::create($ry);
            $ry = $this->transform($ry, PI()/2);

            // add all
            $matrix = $c->add($rx)->add($ry);
        }

        // store dc and dsi
        $this->ele_ds_mat[$i][$j] = $rx->add($ry);

        // return
        return $matrix;
    }

    public function get_fc2($ec1, $ec2)
    {
        $fc2 = 0;
        $beta = min(((0.85 - 0.27 * ($ec1 / $ec2))**-1), 1.0);
        $ep = $beta * $this->ec_dash;
        $fp = $beta * $this->fc_dash;

        if(0 < abs($ec2) && abs($ec2) < abs($ep)){
            $fc2 = -$fp * (2 * ($ec2 / $ep) - ($ec2 / $ep)**2);
        }else if(abs($ep) < abs($ec2) && abs($ec2) < abs($this->ec_dash)){
            $fc2 = -$fp;
        }else{
            $fc2 = -$fp * (2 * ($ec2 / $this->ec_dash) - ($ec2 / $this->ec_dash)**2);
        }
        return $fc2;
    }


    public function get_fc2_old($ec1, $ec2)
    {
        $fc2 = 0;
        $beta = min(((0.85 - 0.27 * ($ec1 / $ec2))**-1), 1.0);
        $ep = $beta * $this->ec_dash;
        $fp = $beta * $this->fc_dash;

        if(0 < $ec2 && $ec2 < $ep){
            $fc2 = -$fp * (2 * ($ec2 / $ep) - ($ec2 / $ep)**2);
        }else if($ep < $ec2 && $ec2 < $this->ec_dash){
            $fc2 = -$fp;
        }else{
            $fc2 = -$fp * (2 * ($ec2 / $this->ec_dash) - ($ec2 / $this->ec_dash)**2);
        }
        return $fc2;
    }

    // theta used in transformation matrix
    public function calculate_theta($ex, $ey, $Yxy, $thetac = false)
    {

        // calculate theta
        if(abs($ex - $ey) < 3 * $this->SNUM){
          if($Yxy > 0){
            $theta = PI()/4;
          }else{
            $theta = 3 * PI()/4;
          }
        }else if(abs($Yxy) < 3 * $this->SNUM){
          if($ex - $ey > 0){
            $theta = 0;
          }else{
            $theta = PI()/2;
          }
        }else{
            $theta = 0.5 * atan($Yxy / ($ex - $ey));
            if($ex - $ey < 0){
              $theta = PI()/2 + $theta;
            }
        }

        // return
        return ($thetac ? ($theta - PI()/2) : ($theta));
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
        $t[1][2] = -1 * sin($theta) * cos($theta);
        $t[2][0] = -2 * sin($theta) * cos($theta);
        $t[2][1] = +2 * sin($theta) * cos($theta);
        $t[2][2] = cos($theta) * cos($theta) - sin($theta) * sin($theta);
        $t = MatrixFactory::create($t);
        return $t->transpose()->multiply($mat)->multiply($t);
    }

}
