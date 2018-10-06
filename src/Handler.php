<?php
/**
 * Request validator
*/
declare(strict_types = 1);
namespace Forensic\Handler;

use Forensic\Handler\Interfaces\ValidatorInterface;

use Forensic\Handler\Exceptions\DataSourceNotRecognizedException;
use Forensic\Handler\Exceptions\DataSourceNotSetException;
use Forensic\Handler\Exceptions\RulesNotSetException;
use Forensic\Handler\Exceptions\DataNotFoundException;
use Forensic\Handler\Interfaces\DBCheckerInterface;

ini_set('filter.default', 'full_special_chars');
ini_set('filter.default_flags', '0');

class Handler
{
    /**
     * the raw data to be handled and processed
    */
    private $_source = null;

    /**
     * array of added fields
    */
    private $_added_fields = [];

    /**
     * array of rules to apply
    */
    private $_rules = null;

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
     * array of processed data
    */
    private $_data = [];

    protected function getDBChecksMethodMap()
    {
        return [
            //check if exist method map
            'ifexist' => 'checkIfExists',

            //check if not exists method map
            'ifnotexist' => 'checkIfNotExists',
        ];
    }

    /**
     * returns rule type to validation method map
     *
     *@return array
    */
    protected function getRuleTypesMethodMap()
    {
        return [
            //text validator
            'text' => 'validateText',

            // date validator
            'date' => 'validateDate',

            //integer validation methods
            'int' => 'validateInteger',
            'pint' => 'validatePInteger',
            'nint' => 'validateNInteger',

            //number validation methods
            'float' => 'validateFloat',
            'pfloat' => 'validatePFloat',
            'nfloat' => 'validateNFloat',

            //boolean validation
            'bool' => '',

            //email validation
            'email' => 'validateEmail',

            //url validation
            'url' => 'validateURL',

            //choice validation
            'choice' => 'validateChoice',

            //range validation
            'range' => 'validateRange',

            //file validation
            'file' => 'validateFile'
        ];
    }

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
     * sets the data for a given field, only priviledged codes can set data
     *
     *@param string $field - the field data
     *@param mixed $value
     *@return self
    */
    protected function setData(string $field, $value)
    {
        $this->_data[$field] = $value;
        return $this;
    }

    /**
     * runs the database checks
     *
     *@param bool $required - boolean indicating if field is required
     *@param string $field - field being checked
     *@param mixed $value - field value
     *@param array $db_checks - the database check items
     *@param int $index - the value index position
     *@return bool
    */
    public function runDBChecks(bool $required, string $field, $value, array $db_checks,
        int $index)
    {
        $db_checker = $this->_db_checker;
        foreach ($db_checks as $db_check)
        {
            $check = Util::value('check', $db_check, '');
            if($check === '')
                continue;

            $method = Util::value($check, $this->getDBChecksMethodMap(), 'null');
            if ($method === 'null')
            {
                $warning = $check . ' is not a recognised db check rule';
                trigger_error($warning, E_USER_WARNING);
            }
            else if ($db_checker->{$method}($required, $field, $value, $db_check, $index))
            {
                break;
            }
        }
        return $this->succeeds();
    }

    /**
     * runs database checks on the fields
     *
     *@param array $fields - the array of fields to validate
     *@param bool $required - boolean value indicating if field is required
    */
    public function validateDBChecks(array $fields, bool $required)
    {
        foreach($fields as $field)
        {
            $db_checks = $this->_db_checks[$field];
            if (count($db_checks) === 0)
                continue;

            //collect field values.
            $values = Util::makeArray($field, $this->_data);
            foreach($values as $index => $value)
            {
                if (!$this->runDBChecks($required, $field, $value, $db_checks, $index))
                    break; //break on first db check error
            }
        }
    }

    /**
     * runs validation on the given field whose value is the given value
     *
     *@param bool $required - boolean indicating if field is required
     *@param string $field - field to validate
     *@param mixed $value - field value
     *@param array $options - the rule options
     *@param int $index - the value index position
     *@return bool
    */
    protected function runValidation(bool $required, string $field, $value, array $options,
        int $index)
    {
        $validator = $this->_validator;

        $rule_type = $options['type'];
        $method = Util::value($rule_type, $this->getRuleTypesMethodMap(), 'null');

        if ($method === 'null')
        {
            $warning = $rule_type . ' is not a recognised validation type';
            trigger_error($warning, E_USER_WARNING);
        }
        else if ($method !== '')
        {
            if ($this->isFileField($field))
            {
                $new_value = $value;
                $validator->{$method}($required, $field, $value, $options, $index, $new_value);

                //put the calculated file hash
                if(is_array($this->_data[$field]))
                    $this->_data[$field][$index] = $new_value;
                else
                    $this->_data[$field] = $new_value;
            }
            else
            {
                $validator->{$method}($required, $field, $value, $options, $index);
            }
        }
        return $validator->succeeds();
    }

    /**
     * validate the fields
     *
     *@param array $fields - the array of fields to validate
     *@param bool $required - boolean value indicating if field is required
    */
    protected function validateFields(array $fields, bool $required)
    {
        foreach($fields as $field)
        {
            $rules = $this->_rule_options[$field];
            $values = Util::makeArray($this->_data[$field]);

            foreach($values as $index => $value)
            {
                if (!$this->runValidation($required, $field, $value, $rules, $index))
                    break; //break on first error
            }
        }
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

        if (Util::keySetAndTrue('toUpper', $filters))
            $value = strtoupper($value);

        else if (Util::keySetAndTrue('toLower', $filters))
            $value = strtolower($value);

        switch($filters['type'])
        {
            case 'email':
                $value = filter_var($value, FILTER_SANITIZE_EMAIL);
                break;

            case 'url';
                $value = filter_var($value, FILTER_SANITIZE_URL);
                break;

            case 'int':
            case 'pint':
            case 'nint':
                if (Util::isNumeric($value))
                    $value = intval($value);
                break;

            case 'float':
            case 'pfloat':
            case 'nfloat':
                if (Util::isNumeric($value))
                    $value = floatval($value);
                break;

            case 'bool':
                if (preg_match('/^(false|off|0|nil|null|no|undefined)$/i', $value) || $value === '')
                    $value = false;
                else
                    $value = true;
                break;
        }
        return $value;
    }

    /**
     * returns true if the field type is a file type
     *
     *@param string $field - the field
     *@return bool
    */
    protected function isFileField(string $field)
    {
        switch($this->_rule_options[$field]['type'])
        {
            case 'file':
            case 'media':
                return true;
            default:
                return false;
        }
    }

    /**
     * checks if the given field is missing
     *
     *@return bool
    */
    protected function fieldIsMissing(string $field)
    {
        $is_file_field = $this->isFileField($field);

        $target = null;
        if ($is_file_field)
        {
            if (!array_key_exists($field, $_FILES))
                $_FILES[$field] = [];

            $target = Util::value('name', $_FILES[$field]);
        }
        else
        {
            $target = Util::value($field, $this->_source);
        }

        $is_missing = true;
        if(!is_null($target) && $target !== '')
        {
            $is_missing = false;
            if (is_array($target))
            {
                $items = [];
                foreach($target as $item)
                {
                    if (!is_null($item) && $item !== '')
                        $items[] = $item;
                }

                if ($is_file_field)
                    $_FILES[$field]['name'] = $items;
                else
                    $this->_source[$field] = $items;

                if (count($items) === 0)
                    $is_missing = true;
            }
        }

        return $is_missing;
    }

    /**
     * runs the get field call
     *
     *@param array $fields - array of fields
    */
    protected function runGetFields(array $fields)
    {
        foreach($fields as $field)
        {
            $value = null;

            if($this->isFileField($field))
                $value = Util::value('name', $_FILES[$field]);
            else
                $value = $this->_source[$field];

            $filters = $this->_filters[$field];
            $this->setData($field, $this->filterValue($value, $filters));
        }
    }

    /**
     * gets the fields
    */
    protected function getFields()
    {
        //get required fields
        $this->runGetFields($this->_required_fields);

        //resolve default values
        $this->resolveOptions($this->_default_values);

        //use default value for optional fields that are missing
        foreach ($this->_optional_fields as $field)
        {
            if ($this->fieldIsMissing($field) && !$this->isFileField($field))
                $this->_source[$field] = $this->_default_values[$field];
        }

        //get optional fields
        $this->runGetFields($this->_optional_fields);
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
                $this->setError($field, $this->_hints[$field]);
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
     * resolve db checks 'check' rule, replace all doesnot, doesnt with not, replace exists
     * with exist
     *
     *@param array $db_check - the database check detail
     *@return array
    */
    public function resolveDBChecks(array $db_check): array
    {
        $check = Util::value('check', $db_check, null);
        if (!is_null($check))
        {
            $db_check['check'] = preg_replace([
                '/(doesnot|doesnt)/',
                '/exists/'
            ], [
                'not',
                'exist'
            ], strtolower($check));
        }
        return $db_check;
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
            '/positive/i',
            '/negative/i',
            '/(number|money)/i',
            '/boolean/i',
            '/string/i'
        ], [
            'int',
            'p',
            'n',
            'float',
            'bool',
            'text'
        ], strtolower($type));
    }

    /**
     * processes the rules, extracting the portions as the need be
    */
    protected function processRules()
    {
        foreach($this->_rules as $field => $rule)
        {
            $type = $this->resolveType(Util::value('type', $rule, 'text'));

            $this->_db_checks[$field] = array_map(
                [$this, 'resolveDBChecks'],
                Util::arrayValue('checks', $rule)
            );
            $this->_filters[$field] = Util::arrayValue('filters', $rule);
            $this->_rule_options[$field] = Util::arrayValue('options', $rule);

            $this->_rule_options[$field]['type'] = $this->_filters[$field]['type'] = $type;

            $require_if = Util::arrayValue('requireIf', $rule, []);
            $condition = Util::value('condition', $require_if, '');

            if ($condition !== '')
            {
                $required = false;

                $_field = Util::value('field', $require_if, '');
                $_field_value = Util::value($_field, $this->_source);

                $_value = Util::value('value', $require_if, '');

                //checkbox and radio inputs are only set if they are checked
                switch(strtolower($condition))
                {
                    case 'checked':
                        if (!is_null($_field_value))
                            $required = true;
                        break;

                    case 'notchecked':
                        if (is_null($_field_value))
                            $required = true;
                        break;

                    case 'equals':
                    case 'equal':
                        if ($_value == $_field_value)
                            $required = true;
                        break;

                    case 'notequals':
                    case 'notequal':
                        if ($_value != $_field_value)
                            $required = true;
                        break;
                }
                $rule['required'] = $required;
            }
            Util::unsetFromArray('requireIf', $rule);

            //boolean fields are optional by default
            if (Util::keyNotSetOrTrue('required', $rule) && $type !== 'bool')
            {
                $this->_required_fields[] = $field;
                $this->_hints[$field] = Util::value('hint', $rule, $field . ' is required');
            }
            else
            {
                $this->_optional_fields[] = $field;
                $this->_default_values[$field] = Util::value('default', $rule);
            }
        }
    }

    /**
     * combines the source and the extended_fields
    */
    protected function mergeSource()
    {
        $this->_source = array_merge($this->_source, $this->_added_fields);
        return $this;
    }

    /**
     * returns boolean indicating if the execute call should proceed
     *@return bool
     *@throws DataSourceNotSetException
     *@throws RulesNotSetException
    */
    protected function shouldExecute()
    {
        if (!$this->_executed)
        {
            if (is_null($this->_source))
                throw new DataSourceNotSetException('No data source set');

            if (is_null($this->_rules))
                throw new RulesNotSetException('No validation rules set');

            return true;
        }
        return false;
    }

    /**
     *@param string|array [$source] - the data source
     *@param array [$rules] - rules to be applied on data
     *@param ValidatorInterface [$validator] - the validator, defaults to an internal validator
     *@param DBCheckerInfterface [$db_checker] - the db checker, defaults to an internal dbchecker
    */
    public function __construct($source = null, array $rules = null,
        ValidatorInterface $validator = null, DBCheckerInterface $db_checker = null)
    {
        if (is_null($validator))
            $validator = new Validator();

        if (is_null($db_checker))
            $db_checker = new DBChecker();

        $this->_executed = false;
        $this->setSource($source);
        $this->setRules($rules);
        $this->setValidator($validator);
        $this->setDBChecker($db_checker);
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
                    $this->_source = $_GET;
                    break;
                case 'post':
                    $this->_source = $_POST;
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
            $this->_rules = $rules;

        return $this;
    }

    /**
     * sets the validator
     *
     *@param ValidatorInterface $validator - the validator
     *@return self
    */
    public function setValidator(ValidatorInterface $validator)
    {
        $this->_validator = $validator;
        $this->_validator->setErrorBag($this->_errors);

        return $this;
    }

    /**
     * sets the db checker
     *
     *@param DBCheckerInterface $db_checker - the db checker
     *@return self
    */
    public function setDBChecker(DBCheckerInterface $db_checker)
    {
        $this->_db_checker = $db_checker;
        $this->_db_checker->setErrorBag($this->_errors);

        return $this;
    }

    /**
     * adds field to the existing source
     *
     *@param string $fieldname - the field name
     *@param mixed $value - the field value
     *@return self
    */
    public function addField(string $fieldname, $value)
    {
        $this->_added_fields[$fieldname] = $value;
        return $this;
    }

    /**
     * adds one or more fields to the existing source
     *
     *@param array $fields - array of field name => value pairs
     *@return self
    */
    public function addFields(array $fields)
    {
        foreach($fields as $fieldname => $value)
            $this->addField($fieldname, $value);

        return $this;
    }

    /**
     * executes the handler
     *
     *@return bool
     *@throws DataSourceNotSetException
     *@throws RulesNotSetException
    */
    public function execute()
    {
        if ($this->shouldExecute())
        {
            $this->_executed = true;

            $this->mergeSource();
            $this->processRules();

            $this->resolveOptions($this->_hints); //resolve hints
            if ($this->checkMissingFields())
            {
                $this->getFields();

                $this->resolveOptions($this->_rule_options);
                $this->resolveOptions($this->_db_checks);

                $this->validateFields($this->_required_fields, true);
                $this->validateFields($this->_optional_fields, false);

                if ($this->succeeds())
                {
                    $this->validateDBChecks($this->_required_fields, true);
                    $this->validateDBChecks($this->_optional_fields, false);
                }
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
    public function getError(string $key = null)
    {
        if (count($this->_errors) > 0)
        {
            if (!is_null($key) && array_key_exists($key, $this->_errors))
                return $this->_errors[$key];

            else if (is_null($key))
                return current($this->_errors);
        }
        return null;
    }

    /**
     * returns array of all errors
     *
     *@return array
    */
    public function getErrors()
    {
        return $this->_errors;
    }

    /**
     * returns the data for the given key if it exists, or null
     *@return string|null
     *@throws DataNotFoundException
    */
    public function getData(string $key)
    {
        if (array_key_exists($key, $this->_data))
            return $this->_data[$key];
        else
            throw new DataNotFoundException('No data set for the given key: ' . $key);
    }

    /**
     * returns array of all data
     *
     *@return array
    */
    public function getAllData()
    {
        return $this->_data;
    }

    /**
     * overload the data properties to make them accessible directly on the instance
    */
    public function __get(string $name)
    {
        try
        {
            return $this->getData($name);
        }
        catch(DataNotFoundException $ex)
        {
            // replace underscores with hyphen
            return $this->getData(preg_replace('/_/', '-', $name));
        }
    }
}