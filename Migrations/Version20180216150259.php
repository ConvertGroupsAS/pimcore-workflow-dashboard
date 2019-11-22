<?php
/**
 * @date        16/02/2018
 * @author      Korneliusz Kirsz <kkirsz@divante.pl>
 * @copyright   Copyright (c) 2017 DIVANTE (http://divante.pl)
 */

namespace Divante\WorkflowBoardBundle\Migrations;

use Divante\WorkflowBoardBundle\DivanteWorkflowBoardBundle;
use Doctrine\DBAL\Schema\Schema;
use Pimcore\Migrations\Migration\AbstractPimcoreMigration;
use Pimcore\Model\User;

/**
 * Class Version20180216150259
 * @package Divante\WorkflowBoardBundle\Migrations
 */
class Version20180216150259 extends AbstractPimcoreMigration
{
    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        User\Permission\Definition::create(DivanteWorkflowBoardBundle::PERMISSION_WORKFLOW_BOARD);
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
    }
}
