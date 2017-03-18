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
    private $et_dash = -1.80e-3; // dimensionless
    private $rho_x = 2; // %
    private $rho_y = 1.5; // %
    private $Ec = 24200; // MPa
    private $Es = 200000; // Mpa
    private $nu = 0.30; // Mpa
    private $fyx = 402; //Mpa
    private $length = ['a' => 0.89, 'b' => 0.89]; // in meters
    private $t = 0.070; // thickness meters
    private $num_elements = 10; // in each direction
    private $ele_d_mat = [];
    private $ele_k_mat = [];
    private $global_k_mat;


    public function __construct()
    {


    }

    public function run()
    {
        //for global stiffness
        $this->getGlobalStiffness();

        if(DEBUG){
            $this->show($this->ele_d_mat[0]);
            $this->show($this->ele_k_mat[0]);
            $this->table($this->global_k_mat);
            $this->dump('sym: '. $this->global_k_mat->isSymmetric());
        }
        die();
    }

    public function getGlobalStiffness()
    {
        // get individual matrices
        $ele_d_mat = [];
        $ele_k_mat = [];
        for ($i = 0; $i < $this->num_elements; $i++) {
            $ele_d_mat[$i] = $this->getElementWiseMeterialStiffness();
            $ele_k_mat[$i] = $this->getElementWiseGlobalStiffness($ele_d_mat[$i]);
        }

        // form global matrix
        $dofs =  4 * (1 + $this->num_elements);
        $global_k_mat = $this->initialize_matrix($dofs);

        //fill in data
        for ($i = 0; $i < $this->num_elements; $i++) {
            for ($row = 0; $row < 8; $row++) {
                for ($col = 0; $col < 8; $col++) {
                    $global_k_mat[4 * $i + $row][4 * $i + $col] += $ele_k_mat[$i][$row][$col];
                }
            }
        }

        //store it
        $this->ele_d_mat = $ele_d_mat;
        $this->ele_k_mat = $ele_k_mat;
        $this->global_k_mat = MatrixFactory::create($global_k_mat);
    }

    public function getElementWiseMeterialStiffness()
    {

        //debug matrix pg 137
        $matrix = $this->initialize_matrix(3);
        $matrix[0][0] = 9948;
        $matrix[0][1] = 4805;
        $matrix[0][2] = -4794;
        $matrix[1][0] = 4805;
        $matrix[1][1] = 7287;
        $matrix[1][2] = -5593;
        $matrix[2][0] = -4794;
        $matrix[2][1] = -5593;
        $matrix[2][2] = 5580;
        return MatrixFactory::create($matrix);

        // check stage type : linear or non linear
        $islinear = true;
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

        }else{
            // for reinf - x
            $rx = $this->initialize_matrix(3);
            $rx[0][0] = $this->rho_x * $this->Es / 100; //@todo change Es
            $rx = MatrixFactory::create($rx);
            $rx = $this->transform($rx, deg2rad(0));

            // for reinf - y
            $ry = $this->initialize_matrix(3);
            $ry[0][0] = $this->rho_y * $this->Es / 100; //@todo change Es
            $ry = MatrixFactory::create($ry);
            $ry = $this->transform($ry, deg2rad(90));

            // for concrete
            $c = $this->initialize_matrix(3);
            $c[0][0] = 805; //@todo change it
            $c[1][1] = 21650; //@todo change it
            $c[2][2] = ($c[1][1] * $c[0][0]) / ($c[1][1] + $c[0][0]);
            $c = MatrixFactory::create($c);
            $c = $this->transform($c, deg2rad(45));

            // add all
            $matrix = $c->add($rx)->add($ry);
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
