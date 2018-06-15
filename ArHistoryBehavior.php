<?php

namespace mgcode\arhistory;

use mgcode\helpers\ArrayHelper;
use yii\base\Application;
use yii\base\Behavior;
use yii\base\InvalidValueException;
use yii\db\BaseActiveRecord;

/**
 * @link https://github.com/mg-code/yii2-ar-history
 * @author Maris Graudins <maris.graudins@mg-software.eu>
 * @property BaseActiveRecord $owner
 */
class ArHistoryBehavior extends Behavior
{
    /**
     * @var array Holds list of records that should be processed after request
     */
    public static $queue = [];

    /**
     * @var string Relation name which holds all related translations
     */
    public $relation = 'history';

    /**
     * @var \Closure Properties that are filtered out.
     * @see \yii\helpers\ArrayHelper::toArray() for details on how properties are being parsed out.
     */
    public $properties;

    /** @inheritdoc */
    public function init()
    {
        parent::init();
        if ($this->relation === null) {
            throw new InvalidValueException('`relation` property must be set.');
        }
    }

    /** @inheritdoc */
    public function attach($owner)
    {
        parent::attach($owner);
        if (!($owner instanceof BaseActiveRecord)) {
            throw new InvalidValueException('`owner` of behavior must be BaseActiveRecord');
        }
        // Check if relation exists
        $owner->getRelation($this->relation, true);
    }

    /** @inheritdoc */
    public function events()
    {
        return [
            BaseActiveRecord::EVENT_AFTER_INSERT => 'onSave',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'onSave',
        ];
    }

    public function onSave()
    {
        // Add new row to queue
        $row = [
            get_class($this->owner),
            $this->owner->primaryKey,
        ];
        if (!in_array($row, static::$queue)) {
            static::$queue[] = $row;
        }

        // Update history on after request
        \Yii::$app->on(Application::EVENT_AFTER_REQUEST, function () {
            while (count(static::$queue) > 0) {
                list ($class, $primaryKey) = array_shift(static::$queue);
                /** @var $model BaseActiveRecord */
                if (!($model = $class::findOne($primaryKey))) {
                    continue;
                }

                // Calculate revision
                $revision = (int) $model->getRelation($this->relation)
                    ->max('revision');
                $revision++;

                // Build state
                $state = ArrayHelper::toArray($model, $this->properties);

                // Build history model
                $historyClass = $this->owner->getRelation($this->relation)->modelClass;
                $historyClass = new $historyClass([
                    'revision' => $revision,
                    'state' => $state,
                ]);

                // Add history entry
                $model->link($this->relation, $historyClass);
            }
        });
    }
}