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
 *          'NamedScope.NamedScope'
 *      );
 *
 *      var $namedScope = array(
 *          'active' => array(
 *              'conditions' => array(
 *                  'User.is_active' => true
 *              )
 *          ),
 *          'limit' => array(
 *              'limit' => 10
 *          )
 *      );
 *
 *  Then call this in my User controller:
 *
 *      $active_users = $this->User->findActive('all');
 *      $active_users = $this->User->findActiveAndLimit('all');
 *
 *  or this:
 *
 *      $active_users = $this->User->find('all', array('namedScope' => 'active'));
 *      $active_users = $this->User->find('all', array('namedScope' => array('active', 'limit')));
 *
 *  You can even pass in the standard find params to both calls.
 *
 */
class NamedScopeBehavior extends ModelBehavior
{
    var $_defaultSettings = array(
        'varName' => 'namedScope',
        'queryKey' => 'namedScope'
    );

    var $_defaultRuntime = array(
        'hasGroup' => false,
    );

    var $runtime = array();

    /**
     * Instantiates the behavior and sets the magic methods
     *
     * @param object $model The Model object
     * @param array $settings Array of scope properties
     */
    function setup(&$model, $settings = array())
    {
        $this->settings[$model->alias] = Set::merge($this->_defaultSettings, $settings);
        $this->runtime[$model->alias] = $this->_defaultRuntime;

        if (empty($model->{$this->settings[$model->alias]['varName']})) {
            $model->{$this->settings[$model->alias]['varName']} = array();

        } else {
            $model->{$this->settings[$model->alias]['varName']} = Set::normalize($model->{$this->settings[$model->alias]['varName']});
        }

        $this->mapMethods['/find.+/'] = '_find';
    }

    /**
     * Before find
     *
     * @see libs/model/ModelBehavior::beforeFind()
     */
    function beforeFind(&$model, $query)
    {
        if (!isset($query[$this->settings[$model->alias]['queryKey']])) {
            return true;
        }

        $scopeKeys = (array)$query[$this->settings[$model->alias]['queryKey']];
        unset($query[$this->settings[$model->alias]['queryKey']]);

        foreach ($scopeKeys as $scopeKey) {
            $query = $this->_mergeParams($model, $query, $scopeKey);
        }

        if (isset($query['group'])) {
            $this->runtime[$model->alias]['hasGroup'] = true;
        }

        return $query;
    }

    /**
     * After find
     *
     * @see libs/model/ModelBehavior::afterFind()
     */
    function afterFind(&$model, $results, $primary = false)
    {
        if ($primary && $model->findQueryType == 'count' && $this->runtime[$model->alias]['hasGroup']) {
            if (isset($results[0][0]['count'])) {
                $results[0][0]['count'] = $model->getAffectedRows();

            } elseif (isset($results[0][$this->alias]['count'])) {
                $results[0][$this->alias]['count'] = $model->getAffectedRows();
            }
        }

        return $results;
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
        $scopeNames = array_keys($model->{$this->settings[$model->alias]['varName']});
        arsort($scopeNames);

        $useScopes = array();

        if (preg_match_all('/(' . implode('|', $scopeNames) . ')(and)?/i', $method, $matches)) {
            array_pop($matches[2]);
            $operators = Set::filter($matches[2]);

            if ($operators === false) {
                $operators = array();
            }

            if (
                count($operators) == count($matches[1]) - 1
                && strlen(implode('', $operators)) + strlen(implode('', $matches[1])) == strlen($method)
            ) {
                $useScopes = array_merge($useScopes, $matches[1]);
            }
        }

        if (is_array($query) && isset($query[$this->settings[$model->alias]['queryKey']])) {
            $useScopes = array_merge($useScopes, (array)$query[$this->settings[$model->alias]['queryKey']]);
            unset($query[$this->settings[$model->alias]['queryKey']]);
        }

        if ($useScopes) {
            $useScopes = array_unique($useScopes);

            foreach ($useScopes as $useScope) {
                $query = $this->_mergeParams($model, $query, $useScope);
            }

            if (!$type) {
                $type = 'first';
            }

            return $model->dispatchMethod('find', array($type, $query));
        }

        return array('unhandled');
    }

    /**
     * Merges params, to ensure that all required params are set. The params passed to the find call
     * always take precedence, over those set in the behavior settings.
     *
     * @param object $model The model object
     * @param array $params The params passed to the find call
     * @param string $scopeName The scope name
     *
     * @return array Merged params
     */
    function _mergeParams(&$model, $params, $scope)
    {
        $scope = strtolower($scope);
        $scopes = array_combine(
            array_map('strtolower', array_keys($model->{$this->settings[$model->alias]['varName']})),
            $model->{$this->settings[$model->alias]['varName']}
        );

        if (empty($scopes[$scope])) {
            return $params;
        }

        foreach ($scopes[$scope] as $key => $value) {
            if (is_array($value)) {
                $params[$key] = isset($params[$key])
                              ? Set::numeric(array_keys($value)) ? array_merge($params[$key], $value)
                                                                 : Set::merge($params[$key], $value)
                              : $value;
            } else {
                if (!isset($params[$key]) || empty($params[$key])) {
                    $params[$key] = $value;
                }
            }
        }
        return $params;
    }
}
