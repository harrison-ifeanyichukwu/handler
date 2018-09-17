<?php
/**
 * Request validator
*/
declare(strict_types = 1);
namespace Forensic\Handler;

use Forensic\Handler\Exceptions\DataNotFoundException;
use Forensic\Handler\Exceptions\RuleNotFoundException;
use Forensic\Handler\Interfaces\ValidatorInterface;
use Forensic\Handler\Exceptions\DataSourceNotRecognizedException;

ini_set('filter.default', 'full_special_chars');
ini_set('filter.default_flags', '0');

class Handler
{
    /**
     * the raw data to be handled and processed
    */
    private $_source = [];

    /**
     * array of rules to apply
    */
    private $_rules = [];

    /**
     * the validator instance
    */
    private $_validator = null;

    /**
     * boolean value indicating if the execute method has been called
    */
    private $_executed = false;

    /**
     * array of required fields
    */
    private $_required_fields = [];

    /**
     * error hints for required fields
    */
    private $_hints = [];

    /**
     * array of optional fields
    */
    private $_optional_fields = [];

    /**
     * array of default values for optional fields
    */
    private $_default_values = [];

    /**
     * array of filters for the fields
    */
    private $_filters = [];

    /**
     * array of rule options for the fields
    */
    private $_rule_options = [];

    /**
     * array containing found errors
    */
    private $_errors = [];

    /**
     * array of database checks for the fields
    */
    private $_db_checks = [];


    /**
     * sets error message for a given field
     *
     *@param string $field - the field
     *@param string $err - the error message
     *@return self
    */
    protected function setError(string $field, string $err)
    {
        $this->_errors[$field] = $err;
        return $this;
    }

    /**
     * runs data filteration on the given value
     *
     *@param array|string|null $value - the value or array of values
     *@param array $filters - array of filters
     *@return mixed
    */
    protected function filterValue($value, array $filters)
    {
        if (is_array($value))
        {
            $filtered_values = [];
            foreach($value as $current)
            {
                $filtered_values[] = $this->filterValue($current, $filters);
            }
            return $filtered_values;
        }

        $value = strval($value);

        if (Util::keyNotSetOrTrue('decode', $filters))
            $value = urldecode($value);

        if (Util::keyNotSetOrTrue('trim', $filters))
            $value = trim($value);

        if (Util::keyNotSetOrTrue('stripTags', $filters))
            $value = strip_tags($value);

        switch(strtolower($filters['type']))
        {
            case 'email':
                $value = filter_var($value, FILTER_SANITIZE_EMAIL);
                break;
            case 'url';
                $value = filter_var($value, FILTER_SANITIZE_URL);
                break;
            case 'int':
            case 'positiveint':
            case 'negativeint':
                if (Util::isNumeric($value))
                    $value = intval($value);
                break;
            case 'float':
            case 'positivefloat':
            case 'negativefloat':
                if (Util::isNumeric($value))
                    $value = floatval($value);
                break;
            case 'bool':
                if (preg_match('/^false|off|0|nil|null|no|undefined$/i', $value) || $value = '')
                    $value = false;
                else
                    $value = true;
                break;

        }
        return $value;
    }

    /**
     * gets the fields
    */
    protected function getFields()
    {
        //start with required fields
        foreach ($this->_required_fields as $field) {
            $value = $this->_source[$field];
            $filters = $this->_filters[$field];
            $this->_data[$field] = $this->filterValue($value, $filters);
        }

        //resolve default fields
        $this->resolveOptions($this->_default_values);

        //get optional fields
        foreach ($this->_optional_fields as $field) {
            //if the optional field is missing, pick its default value
            $value = null;
            if ($this->fieldIsMissing($field))
                $value = $this->_default_values[$field];
            else
                $value = $this->_source[$field];

            $filters = $this->_filters[$field];
            $this->_data[$field] = $this->filterValue($value, $filters);
        }
    }

    /**
     * checks if the given field is missing
     *
     *@return bool
    */
    protected function fieldIsMissing(string $field)
    {
        $source = &$this->_source;
        $is_missing = true;
        if (isset($source[$field]) && $source[$field] !== '')
        {
            $is_missing = false;
            $values = $source[$field];
            if (is_array($values))
            {
                $source[$field] = [];
                foreach($values as $value)
                {
                    if (!is_null($value) && $value !== '')
                        $source[$field][] = $value;
                }

                if (count($source[$field]) === 0)
                    $is_missing = true;
            }
        }
        return $is_missing;
    }

    /**
     * checks for missing fields
     *
     *@return bool
    */
    protected function checkMissingFields()
    {
        foreach($this->_required_fields as $field)
        {
            if ($this->fieldIsMissing($field))
            {
                $this->setError($field, $this->_hints[$field]);
            }
        }
        return $this->succeeds();
    }

    /**
     * resolves options
     *
     *@param string $field - the option field key
     *@param array|string option - the option to resolve
    */
    protected function resolveOption(string $field, $option)
    {
        if (is_array($option))
        {
            foreach($option as $key => $value)
                $option[$key] = $this->resolveOption($field, $value);

            return $option;
        }

        $value = preg_replace_callback('/\{\s*([^}]+)\s*\}/', function($matches) use ($field) {
            $capture = $matches[1];
            switch(strtolower($capture))
            {
                case '_this':
                    return $field;

                case 'current_timestamp':
                case 'current_datetime':
                case 'current_date':
                    return '' . new DateTime();

                case 'now':
                case 'timestamp':
                case 'current_time':
                    return time();

                default:
                    return Util::value($capture, $this->_data, $matches[0]);
            }
        }, $option);
        return $value;
    }

    /**
     * resolves options.
     *
     *@param array options - the options to resolve
    */
    protected function resolveOptions(array &$options)
    {
        foreach($options as $field => $option)
            $options[$field] = $this->resolveOption($field, $option);

        return $options;
    }

    /**
     * resolves the rule type
     *
     *@param string $type - the rule type
     *@return string
    */
    protected function resolveType(string $type)
    {
        return preg_replace([
            '/integer/i',
            '/number/i',
            '/boolean/i',
            '/string/i'
        ], [
            'int',
            'float',
            'bool',
            'text'
        ], $type);
    }

    /**
     * processes the rules, extracting the portions as the need be
    */
    protected function processRules()
    {
        foreach($this->_rules as $field => $rule)
        {
            if (Util::keyNotSetOrTrue('required', $rule))
            {
                $this->_required_fields[] = $field;
                $this->_hints[$field] = Util::value('hint', $rule, $field . ' is required');
            }
            else
            {
                $this->_optional_fields[] = $field;
                $this->_default_values[$field] = Util::value('default', $rule);
            }

            $this->_db_checks[$field] = Util::arrayValue('checks', $rule);
            $this->_filters[$field] = Util::arrayValue('filters', $rule);
            $this->_rule_options[$field] = Util::arrayValue('options', $rule);

            $type = Util::value('type', $rule, 'text');
            $this->_rule_options[$field]['type'] = $this->_filters[$field]['type'] =
                $this->resolveType($type);
        }
    }

    /**
     * returns boolean indicating if the execute call should proceed
     *@return bool
    */
    protected function shouldExecute()
    {
        if (!$this->_executed)
        {
            if (empty($this->_source))
                throw new DataNotFoundException('no data found to proccess');

            if (empty($this->_rules))
                throw new RuleNotFoundException('no validation rules set');

            return true;
        }
        return false;
    }

    /**
     *@param string|array $source - the data source
     *@param array $rules - rules to be applied on data
    */
    public function __construct($source = null, array $rules = null,
        ValidatorInterface $validator = null)
    {
        if (is_null($validator))
            $validator = new Validator();

        $this->_executed = false;
        $this->setSource($source);
        $this->setRules($rules);
        $this->setValidator($validator);
    }

    /**
     * sets the data source
     *
     *@param string|array $source - the data source
     *@return self
    */
    public function setSource($source = null)
    {
        if (is_string($source))
        {
            $source = strtolower($source);
            switch($source)
            {
                case 'get':
                    $this->_source = &$_GET;
                    break;
                case 'post':
                    $this->_source = &$_POST;
                    break;
                default:
                    $err = $source . ' is not a recognized data source';
                    throw new DataSourceNotRecognizedException($err);
            }
        }

        else if (is_array($source))
        {
            $this->_source = $source;
        }
        return $this;
    }

    /**
     * sets the rules
     *
     *@param array $rules - array of rules
     *@return self
    */
    public function setRules(array $rules = null)
    {
        if (is_array($rules))
        {
            $this->_rules = $rules;
        }
        return $this;
    }

    /**
     * sets the validator
     *
     *@param ValidatorInterface $validator - the validator
    */
    public function setValidator(ValidatorInterface $validator)
    {
        $this->_validator = $validator;
        $this->_validator->setErrorBag($this->_errors);
    }

    /**
     * executes the handler
     *
     *@return bool
    */
    public function execute()
    {
        if ($this->shouldExecute())
        {
            $this->_executed = true;
            $this->processRules();

            $this->resolveOptions($this->_hints); //resolve hints
            if ($this->checkMissingFields())
            {
                $this->getFields();

                $this->resolveOptions($this->_rule_options);
                $this->resolveOptions($this->_db_checks);
            }
        }

        return $this->succeeds();
    }

    /**
     * returns boolean value indicating if the handling went successful
     *
     *@return bool
    */
    public function succeeds()
    {
        return $this->_executed && count($this->_errors) === 0;
    }

    /**
     * returns boolean value indicating if the handling failed
     *
     *@return bool
    */
    public function fails()
    {
        return !$this->succeeds();
    }

    /**
     * returns the error string for the given key if it exists, or null
     *
     *@return string|null
    */
    public function getError(string $key)
    {
        if (array_key_exists($key, $this->_errors))
            return $this->_errors[$key];
        else
            return null;
    }

    /**
     * returns the data for the given key if it exists, or null
     *@return string|null
    */
    public function getData(string $key)
    {
        if (array_key_exists($key, $this->_data))
            return $this->_data[$key];
        else
            return null;
    }
}