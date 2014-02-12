<?php

namespace T4\Orm\Extensions;

use T4\Dbal\QueryBuilder;
use T4\Orm\Exception;
use T4\Orm\Extension;

class Tree
    extends Extension
{

    public function prepareColumns($columns)
    {
        return $columns + [
            '__lft' => ['type' => 'int'],
            '__rgt' => ['type' => 'int'],
            '__lvl' => ['type' => 'int'],
            '__prt' => ['type' => 'link'],
        ];
    }

    public function prepareIndexes($indexes)
    {
        return $indexes + [
            '__lft' => ['columns' => ['__lft']],
            '__rgt' => ['columns' => ['__rgt']],
            '__lvl' => ['columns' => ['__lvl']],
            '__key' => ['columns' => ['__lft', '__rgt', '__lvl']],
            '__prt' => ['columns' => ['__prt']],
        ];
    }

    public function beforeSave(&$model)
    {

        $class = get_class($model);
        $tableName = $class::getTableName();
        /** @var $connection \T4\Dbal\Connection */
        $connection = $class::getDbConnection();

        $model->__prt = (int)$model->__prt;
        if (0 != $model->__prt) {
            $parent = $class::findByPk($model->__prt);
            if (empty($parent))
                return false;
        }

        /**
         * Вставка новой записи в таблицу
         * ID родительской записи определяем по полю __prt
         */
        if ($model->isNew()) {

            /*
             * Запись вставляется, как новый корень дерева
             */
            if ( !isset($parent) ) {
                $query = new QueryBuilder();
                $query->select('MAX(__rgt)')->from($tableName);
                $rgt = (int)$connection->query($query->getQuery())->fetchScalar() + 1;
                $lvl = 0;
                /*
                 * У записи будет существующий родитель
                 */
            } else {
                $rgt = $parent->__rgt;
                $lvl = $parent->__lvl;
            }

            $result = $connection->execute("
                UPDATE `" . $tableName . "`
                SET
                    `__rgt`=__rgt+2,
                    `__lft`=IF(`__lft`>:rgt, `__lft` + 2, `__lft`) WHERE `__rgt`>=:rgt
                ", [':rgt' => $rgt]);
            if (!$result) {
                return false;
            } else {
                $model->__lft = $rgt;
                $model->__rgt = $rgt + 1;
                $model->__lvl = $lvl + 1;
                return true;
            }

            /**
             * Перенос в дереве уже существующей записи
             */
        } else {
            die('ERROR!');
        }

    }

    public function callStatic($class, $method, $argv)
    {
        switch (true) {
            case 'findAllTree' == $method:
                return $class::findAll(['order'=>'__lft']);
                break;
        }
        throw new Exception('Method ' . $method . ' is not found in extension ' . __CLASS__);
    }

    public function call($model, $method, $argv)
    {
        $class = get_class($model);
        switch (true) {
            case 'findAllChildren':
                return $class::findAll([
                    'where'=>'__lft>'.$model->__lft.' AND __rgt<='.$model->__rgt,
                    'order'=>'__lft'
                ]);
            case 'findSubTree':
                return $class::findAll([
                    'where'=>'__lft>='.$model->__lft.' AND __rgt<='.$model->__rgt,
                    'order'=>'__lft'
                ]);
        }
        throw new Exception('Method ' . $method . ' is not found in extension ' . __CLASS__);
    }

}