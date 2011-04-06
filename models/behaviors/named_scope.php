<?php
/**
 * NamedScope Behavior for CakePHP 1.2
 *
 * @copyright     Copyright 2008, Joel Moss (http://developwithstyle.com)
 * @link          http://github.com/joelmoss/cakephp-namedscope
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 *
 *
 * This NamedScope behavior for CakePHP allows you to define named scopes for a model,
 * and then apply them to any find call. It will automagically create a model method,
 * and a method for use with the _findMethods property of the model.
 *
 * Borrowed from an original idea found in Ruby on Rails, and a first attempted for Cake
 * by Michał Szajbe (http://github.com/netguru/namedscopebehavior)
 *
 * Example:
 *
 *  I have a User model and want to return only those which are active. So I define
 *  this in my model:
 *
 *      var $actsAs = array(
 *          'NamedScope' => array(
 *              'active' => array(
 *                  'conditions' => array(
 *                      'User.is_active' => true
 *                  )
 *              )
 *          )
 *      );
 *
 *  Then call this in my User controller:
 *
 *      $active_users = $this->User->findActive('all');
 *
 *  or this:
 *
 *      $active_users = $this->User->find('type', array('named' => 'active'));
 *
 *  You can even pass in the standard find params to both calls.
 *
 */
class NamedScopeBehavior extends ModelBehavior
{
    /**
     * An array of settings set by the $actsAs property
     */
    var $settings = array();

    /**
     * Instantiates the behavior and sets the magic methods
     *
     * @param object $model The Model object
     * @param array $settings Array of scope properties
     */
    function setup(&$model, $settings = array())
    {
        $scopes = array();

        foreach (Set::normalize($settings) as $named => $options) {
            $named = strtolower($named);
            $scopes[$named] = is_array($options) ? $options : array();

            $this->mapMethods['/find' . $named . '/'] = '_find';
        }

        $this->settings[$model->alias] = $scopes;
    }

    /**
     * Before find
     *
     * @see libs/model/ModelBehavior::beforeFind()
     */
    function beforeFind(&$model, $query)
    {
        if (!isset($query['named'])) {
            return true;
        }

        $named = strtolower($query['named']);
        unset($query['named']);

        if (!empty($named) && isset($this->settings[$model->alias][$named])) {
            $query = $this->_mergeParams($model, $query, $named);
        }

        return $query;
    }

    /**
     * Defines a model method that runs Model::find() using the scope properties passed to $actsAs.
     *
     * @param object $model The model object
     * @param string $method The method name
     * @param string $type The find type
     * @param array $query Array of find queries
     *
     * @return array Find results
     */
    function _find(&$model, $method, $type = null, $query = array())
    {
        $method = preg_replace('/^find/', '', $method);
        $query = $this->_mergeParams($model, $query, $method);

        if (isset($query['named'])) {
            unset($query['named']);
        }

        return $model->dispatchMethod('find', array($type, $query));
    }

    /**
     * Merges params, to ensure that all required params are set. The params passed to the find call
     * always take precedence, over those set in the behavior settings.
     *
     * @param object $model The model object
     * @param array $params The params passed to the find call
     * @param string $named The named key
     *
     * @return array Merged params
     */
    function _mergeParams(&$model, $params, $named)
    {
        foreach ($this->settings[$model->alias][strtolower($named)] as $key => $value) {
            if (is_array($value)) {
                $params[$key] = isset($params[$key]) ? Set::merge($params[$key], $value) : $value;
            } else {
                if (!isset($params[$key]) || empty($params[$key])) {
                    $params[$key] = $value;
                }
            }
        }
        return $params;
    }
}