<?php
declare(strict_types = 1);

namespace Forensic\Handler\Test;

use PHPUnit\Framework\TestCase;
use Forensic\Handler\Validator;
use Forensic\Handler\DateTime;
use Forensic\Handler\Exceptions\DirectoryNotFoundException;
use Forensic\Handler\Exceptions\FileMoveException;

class ValidatorTest extends TestCase
{
    private $_validator = null;

    /**
     * provides data used for validating type rules
    */
    public function typeRulesTestDataProvider()
    {
        return [
            //date validation data
            'date correct set' => [
                'validateDate',
                'date-of-birth',
                ['2018-01-04', '20180104', "2018\t01\t04"],
            ],
            'date wrong set' => [
                'validateDate',
                'date-of-birth',
                ['01-04-2018', '2018-01-32'],
                [],
                [
                    '01-04-2018 is not a valid date format',
                    '2018-01-32 is not a valid date',
                ],
            ],

            //integer validation data
            'correct integer set' => [
                'validateInteger',
                'product-number',
                ['12'],
            ],
            'wrong integer set' => [
                'validateInteger',
                'product-number',
                ['a22'],
                [],
                [
                    '"a22" is not a valid integer',
                ],
            ],

            //negative integer validation
            'correct negative integer set' => [
                'validateNInteger',
                'product-number',
                ['-12'],
            ],
            'wrong negative integer set' => [
                'validateNInteger',
                'product-number',
                ['22',],
                [],
                ['22 is not a valid negative integer'],
            ],

            //positive integer validation
            'correct positive integer set' => [
                'validatePInteger',
                'product-number',
                ['12'],
            ],
            'wrong positive integer set' => [
                'validatePInteger',
                'product-number',
                ['-22',],
                [],
                ['-22 is not a valid positive integer'],
            ],

            //float validation
            'correct float set' => [
                'validateFloat',
                'product-number',
                ['12.22', 0.22, '0.2333'],
            ],
            'wrong float set' => [
                'validateFloat',
                'product-number',
                ['-aaa222',],
                [],
                ['"-aaa222" is not a valid number'],
            ],

            //positive float validation
            'correct positive float set' => [
                'validatePFloat',
                'product-number',
                ['12.22', 0.22, '0.2333'],
            ],
            'wrong positive float set' => [
                'validatePFloat',
                'product-number',
                ['-0.222',],
                [],
                ['-0.222 is not a valid positive number'],
            ],

            //negative float validation
            'correct negative float set' => [
                'validateNFloat',
                'product-number',
                ['-12.22', -0.22, '-0.2333'],
            ],
            'wrong negative float set' => [
                'validateNFloat',
                'product-number',
                ['0.222',],
                [],
                ['0.222 is not a valid negative number'],
            ],

            //email validation
            'correct email set' => [
                'validateEmail',
                'email',
                ['Harrisonifeanyichukwu@gmail.com', 'harrisonifeanyichukwu@yahoo.com']
            ],
            'wrong email set' => [
                'validateEmail',
                'email',
                ['Harrisonifeanyichukwu@gmail', 'harrisonifeanyichukwu@yahoo.'],
                [],
                [
                    '"Harrisonifeanyichukwu@gmail" is not a valid email address',
                    '"harrisonifeanyichukwu@yahoo." is not a valid email address',
                ],
            ],

            //url validation
            'correct url set' => [
                'validateURL',
                'website',
                ['example.com', 'www.example.com']
            ],
            'wrong url set' => [
                'validateURL',
                'website',
                ['example'],
                [],
                [
                    '"example" is not a valid url'
                ]
            ],

            //choice validation
            'correct choice set' => [
                'validateChoice',
                'language',
                ['eu', 'en'],
                [
                    'choices' => array('eu', 'en')
                ]
            ],
            'wrong choice set' => [
                'validateChoice',
                'language',
                ['du', 'fr'],
                [
                    'choices' => array('eu', 'en'),
                    'err' => '{this} is not a valid language code'
                ],
                [
                    '"du" is not a valid language code',
                    '"fr" is not a valid language code',
                ]
            ],

            //range validation
            'correct range set' => [
                'validateRange',
                'year',
                ['1996', 2000],
                [
                    'from' => 1990,
                    'to' => 2018
                ]
            ],
            'correct range set 2' => [
                'validateRange',
                'alphabet',
                ['a', 'c'],
                [
                    'from' => 'a',
                    'to' => 'z'
                ]
            ],
            'wrong range set' => [
                'validateRange',
                'year',
                [1978, '2019'],
                [
                    'from' => 1990,
                    'to' => 2018,
                    'err' => '{this} is not a valid year'
                ],
                [
                    '1978 is not a valid year',
                    '2019 is not a valid year',
                ]
            ],
            'wrong range set 2' => [
                'validateRange',
                'alphabet',
                ['b', 'd'],
                [
                    'from' => 'a',
                    'to' => 'z',
                    'step' => 2
                ],
                [
                    '"b" is not an acceptable choice',
                    '"d" is not an acceptable choice'
                ]
            ],

            //password validation
            'correct password validation' => [
                'validatePassword',
                'password1',
                ['random_21', 'random21'],
                [],
            ],
            'correct password validation with matchWith option' => [
                'validatePassword',
                'password1',
                ['random_21'],
                [
                    'matchWith' => 'random_21'
                ],
            ],
            'incorrect password validation' => [
                'validatePassword',
                'password1',
                ['random', '21222222', 'randomnumber'],
                [

                ],
                [
                    'Password should not be less than 8 characters',
                    'Password must contain at least two letter alphabets',
                    'Password must contain at least two non letter alphabets'
                ]
            ],
            'incorrect match with password validation' => [
                'validatePassword',
                'password1',
                ['random_2'],
                [
                    'matchWith' => 'random_21'
                ],
                [
                    'Passwords did not match',
                ]
            ],
        ];
    }

    /**
     * provides data used for validating min limit rule
    */
    public function minLimitRuleTestDataProvider()
    {
        return [
            'set 1' => [
                'validateText',
                'first_name',
                ['Harrison'],
                [
                    'min' => 10,
                    'minErr' => '{_this} should be at least 10 characters long'
                ],
                [
                    'first_name should be at least 10 characters long',
                ],
            ],
            'set 2' => [
                'validateDate',
                'start_date',
                ['2018-01-01'],
                [
                    'min' => new DateTime(),
                ],
                [
                    'start_date should not be less than ' . new DateTime(),
                ],
            ],
        ];
    }

    /**
     * provides data used for validating max limit rule
    */
    public function maxLimitRuleTestDataProvider()
    {
        return [
            'set 1' => [
                'validateInteger',
                'favorite_number',
                [100],
                [
                    'max' => 50,
                ],
                [
                    'favorite_number should not be greater than 50',
                ]
            ],
            'set 2' => [
                'validateDate',
                'start_date',
                ['2018-01-01'],
                [
                    'max' => '2017-12-31',
                ],
                [
                    'start_date should not be greater than 2017-12-31',
                ]
            ],
        ];
    }

    /**
     * provides data used for validating gt limit rule
    */
    public function gtLimitRuleTestDataProvider()
    {
        return [
            'set 1' => [
                'validateText',
                'first_name',
                ['Harrison'],
                [
                    'gt' => 8,
                    'gtErr' => '{_this} should be greater than 8 characters'
                ],
                [
                    'first_name should be greater than 8 characters',
                ]
            ],
            'set 2' => [
                'validateDate',
                'start_date',
                ['2018-01-01'],
                [
                    'gt' => '2018-01-01',
                ],
                [
                    'start_date should be greater than 2018-01-01',
                ]
            ],
        ];
    }

    /**
     * provides data used for validating lt limit rule
    */
    public function ltLimitRuleTestDataProvider()
    {
        return [
            'set 1' => [
                'validateText',
                'first_name',
                ['Harrison'],
                [
                    'lt' => 8,
                    'ltErr' => '{_this} should be less than 8 characters'
                ],
                [
                    'first_name should be less than 8 characters',
                ]
            ],
            'set 2' => [
                'validateDate',
                'start_date',
                ['2018-01-01'],
                [
                    'lt' => '2018-01-01',
                ],
                [
                    'start_date should be less than 2018-01-01',
                ]
            ],
        ];
    }

    /**
     * provides data used for validating regex rule option
    */
    public function regexRuleTestDataProvider()
    {
        return [
            'correct data set' => [
                'validateText',
                'color',
                ['white', 'green', 'red'],
                [
                    'regex' => [
                        'test' => '/^(orange|white|red|black|green|purple|voilet)$/',
                        'err' => '{this} is not a valid color',
                    ],
                ],
            ],

            'wrong data set' => [
                'validateText',
                'color',
                ['london', 'nigeria', '2222'],
                [
                    'regex' => [
                        'test' => '/^(orange|white|red|black|green|purple|voilet)$/',
                        'err' => '{this} is not a valid color',
                    ],
                ],
                [
                    '"london" is not a valid color',
                    '"nigeria" is not a valid color',
                    '2222 is not a valid color'
                ]
            ],
        ];
    }

    /**
     * provides data used for validating regexAll rules
    */
    public function regexAllRuleTestDataProvider()
    {
        return [
            'correct data set' => [
                'validateText',
                'first-name',
                ['Harrison'],
                [
                    'regexAll' => [
                        [
                            //test that the name starts with alphabet
                            'test' => '/^[a-z]/i',
                            'err' => 'first name should start with alphabet'
                        ],
                        [
                            //test that the name is 3-15 characters long
                            'test' => '/^\w{3,14}$/i',
                            'err' => 'first name should be 3 to 15 characters long'
                        ],
                        //test that it ignores options that is not an array
                        '/^[0-9]/',
                    ],
                ],
            ],

            'wrong data set' => [
                'validateText',
                'first-name',
                ['7up', 'Ha'],
                [
                    'regexAll' => [
                        [
                            //test that the name starts with alphabet
                            'test' => '/^[a-z]/i',
                            'err' => 'first name should start with alphabet'
                        ],
                        [
                            //test that the name is 3-15 characters long
                            'test' => '/^\w{3,14}$/i',
                            'err' => 'first name should be 3 to 15 characters long'
                        ],
                    ],
                ],
                [
                    'first name should start with alphabet',
                    'first name should be 3 to 15 characters long'
                ]
            ],
        ];
    }

    /**
     * provides data used for validating regexAny rules
    */
    public function regexAnyRuleTestDataProvider()
    {
        return [
            'correct data set' => [
                'validateURL',
                'website',
                ['https://www.example.com'],
                [
                    'regexAny' => [
                        //test that the website url starts with https or ftp
                        'tests' => ['/^https/', '/^ftp/'],
                        'err' => 'Website url should start with https or ftp protocols',
                    ],
                ],
            ],

            'wrong data set' => [
                'validateURL',
                'website',
                ['http://www.example.com'],
                [
                    'regexAny' => [
                        //test that the website url starts with https or ftp
                        'tests' => ['/^https/', '/^ftp/'],
                        'err' => 'Website url should start with https or ftp protocols',
                    ],
                ],

                [
                    'Website url should start with https or ftp protocols',
                ],
            ],
        ];
    }

    /**
     * provides data used for validating regexNone rules
    */
    public function regexNoneRuleTestDataProvider()
    {
        return [
            'correct data set' => [
                'validateURL',
                'website',
                ['https://www.example.com'],
                [
                    'regexNone' => [
                        // the url should not contain the ftp protocol
                        [
                            'test' => '/^ftp:/i',
                            'err' => '{this} should not contain the ftp protocol'
                        ],
                        // the url should be free of queries
                        [
                            'test' => '/\?.*/',
                            'err' => '{this} should be free of query string'
                        ],
                        //test that it ignores options that is not an array
                        '/^[0-9]/',
                    ],
                ],
            ],

            'wrong data set' => [
                'validateURL',
                'website',
                ['ftp://www.example.com', 'https://www.example.com/index.php?call=search'],
                [
                    'regexNone' => [
                        // the url should not contain the ftp protocol
                        [
                            'test' => '/^ftp:/i',
                            'err' => '{this} should not contain the ftp protocol'
                        ],
                        // the url should be free of queries
                        [
                            'test' => '/\?.*/',
                            'err' => '{this} should be free of query string'
                        ]
                    ],
                ],
                [
                    '"ftp://www.example.com" should not contain the ftp protocol',
                    '"https://www.example.com/index.php?call=search" should be free of query string'
                ]
            ],
        ];
    }

    /**
     * returns data used in testing file upload error
    */
    public function fileUploadErrorDataProvider()
    {
        return [
            'ini size error test' => [
                UPLOAD_ERR_INI_SIZE,
                'File size exceeds upload_max_filesize ini directive',
            ],

            'form size error test' => [
                UPLOAD_ERR_FORM_SIZE,
                'File size exceeds max_file_size html form directive',
            ],

            'no file upload error test' => [
                UPLOAD_ERR_NO_FILE,
                'No file upload found',
            ],

            'no temp folder error test' => [
                UPLOAD_ERR_NO_TMP_DIR,
                'No temp folder found for file storage',
            ],

            'write permission error test' => [
                UPLOAD_ERR_CANT_WRITE,
                'Permission denied while writing file to disk',
            ],

            'php extension error test' => [
                UPLOAD_ERR_EXTENSION,
                'Some loaded extensions aborted file processing',
            ],

            'unknown error test' => [
                41,
                'Unknown file upload error',
            ]
        ];
    }

    /**
     * return array of data used for testing file size limit
    */
    public function fileSizeLimitDataProvider()
    {
        $size = filesize('tests/Helpers/file1.jpg');
        return [
            'min error test' => [
                [
                    'min' => $size + 1,
                    'minErr' => 'Picture should be at least ' . ($size + 1) . ' bytes',
                ],
                true,
                'Picture should be at least ' . ($size + 1) . ' bytes',
            ],
            'min success test' => [
                [
                    'min' => $size,
                ],
                false,
                '',
            ],
        ];
    }

    /**
     * provides data used in testing file extension correctness
    */
    public function fileExtensionTestDataProvider()
    {
        return [
            'spoofed extension test' => [
                'spoofed.png',
                'image/png',
                [],
                true,
                'File extension spoofing detected',
            ],
            'txt extension test' => [
                'file2.txt',
                'text/plain',
                [],
                false,
                '',
            ],
            'binary file extension test' => [
                'file1.jpg',
                'imag/jpeg',
                [],
                false,
                '',
            ],
            'binary file without extension test' => [
                'file3',
                'imag/jpeg',
                [],
                false,
                '',
            ],
            'mimes test' => [
                'file2.txt',
                'text/plain',
                [
                    'mimes' => array('jpeg', 'png', 'gif')
                ],
                true,
                '".txt" file extension not accepted',
            ],
        ];
    }

    public function fileVarietyMethodsTestDataProvider()
    {
        return [
            //image file validation method
            'image file validation set 1' => [
                'validateImage',
                'file1.jpg',
                'image/jpeg',
            ],

            //audio file validation method
            'audio file validation set 1' => [
                'validateAudio',
                'file1.jpg',
                'image/jpeg',
                true,
                '".jpg" file extension not accepted'
            ],

            //video file validation method
            'video file validation set 1' => [
                'validateVideo',
                'file1.jpg',
                'image/jpeg',
                true,
                '".jpg" file extension not accepted'
            ],

            //media file validation method
            'media file validation set 1' => [
                'validateMedia',
                'file1.jpg',
                'image/jpeg',
            ],

            //document file validation method
            'document file validation set 1' => [
                'validateDocument',
                'file2.txt',
                'text/plain',
                true,
                '".txt" file extension not accepted'
            ],

            //document file validation method
            'archive file validation set 1' => [
                'validateArchive',
                'file2.txt',
                'text/plain',
                true,
                '".txt" file extension not accepted'
            ],
        ];
    }

    /**
     * provides data used in testing limiting value resolution
     *
     *@return array
    */
    public function limitingValueResolutionTestDataProvider()
    {
        return [
            'kb resolution test set' => [
                'first-name',
                'Harrison',
                [
                    'min' => '2kb'
                ],
                'first-name should not be less than 2,000 characters'
            ],
            'mb resolution test set' => [
                'first-name',
                'Harrison',
                [
                    'min' => '2.5mb'
                ],
                'first-name should not be less than 2,500,000 characters'
            ],
            'gb resolution test set' => [
                'first-name',
                'Harrison',
                [
                    'min' => '0.5gb'
                ],
                'first-name should not be less than 500,000,000 characters'
            ],
        ];
    }

    public function setUp()
    {
        parent::setUp();
        $this->_validator = new Validator();
    }

    /**
     * test that we can create an instance
    */
    public function testConstruct()
    {
        $validator = new Validator();
        $this->assertInstanceOf(Validator::class, $validator);
    }

    /**
     * test that error bag is set by reference
    */
    public function testErrorBag()
    {
        $error_bag = [
            'first_name' => 'First name field is required',
        ];

        $this->_validator->setErrorBag($error_bag);
        $error_bag['last_name'] = 'Last name field is required';

        $this->assertEquals($error_bag, $this->_validator->getErrorBag());
    }

    /**
     * test that getError method returns the error string for a given field
     * and that it returns the first error if no field is given
    */
    public function testGetError()
    {
        $error_bag = [
            'first_name' => 'First name field is required',
        ];

        $this->_validator->setErrorBag($error_bag);
        $this->assertEquals(
            'First name field is required',
            $this->_validator->getError('first_name')
        );
        $this->assertNull($this->_validator->getError('last_name'));
        $error_bag['last_name'] = 'Last name field is required';

        $this->assertEquals(
            'First name field is required',
            $this->_validator->getError()
        );
    }

    /**
     * test that required fields are validated accordingly
    */
    public function testRequiredFields()
    {
        $this->_validator->validateText(true, 'first_name', null, []);

        $this->assertTrue($this->_validator->fails());
        $this->assertEquals(
            'first_name is required',
            $this->_validator->getError('first_name')
        );
    }

    /**
     * runs test that checks on a specific rule
    */
    public function validationRulesTester(string $method, string $field, array $values,
        array $options = [], array $errs = [], bool $required = true)
    {
        $len = count($values);
        if ($len === 0)
            return;

        $is_errornous = count($errs) > 0? true : false;

        while(--$len >= 0)
        {
            $value = $values[$len];
            $this->_validator->{$method}($required, $field, $value, $options);

            if ($is_errornous)
            {
                $this->assertTrue($this->_validator->fails());
                $error_bag = $this->_validator->getErrorBag();

                $this->assertArrayHasKey($field, $error_bag);
                $this->assertEquals($errs[$len], $error_bag[$field]);
            }
            else
            {
                $this->assertTrue($this->_validator->succeeds());
            }
        }
    }

    /**
     *@dataProvider typeRulesTestDataProvider
    */
    public function testTypeRules(...$args)
    {
        $this->validationRulesTester(...$args);
    }

    /**
     *@dataProvider minLimitRuleTestDataProvider
    */
    public function testMinLimitRule(...$args)
    {
        $this->validationRulesTester(...$args);
    }

    /**
     *@dataProvider maxLimitRuleTestDataProvider
    */
    public function testMaxLimitRule(...$args)
    {
        $this->validationRulesTester(...$args);
    }

    /**
     *@dataProvider gtLimitRuleTestDataProvider
    */
    public function testGtLimitRule(...$args)
    {
        $this->validationRulesTester(...$args);
    }

    /**
     *@dataProvider ltLimitRuleTestDataProvider
    */
    public function testLtLimitRule(...$args)
    {
        $this->validationRulesTester(...$args);
    }

    /**
     *@dataProvider regexRuleTestDataProvider
    */
    public function testRegexRule(...$args)
    {
        $this->validationRulesTester(...$args);
    }

    /**
     *@dataProvider regexAllRuleTestDataProvider
    */
    public function testRegexAllRule(...$args)
    {
        $this->validationRulesTester(...$args);
    }

    /**
     *@dataProvider regexAnyRuleTestDataProvider
    */
    public function testRegexAnyRule(...$args)
    {
        $this->validationRulesTester(...$args);
    }

    /**
     *@dataProvider regexNoneRuleTestDataProvider
    */
    public function testRegexNoneRule(...$args)
    {
        $this->validationRulesTester(...$args);
    }

    /**
     * run file validation feature
    */
    public function runFileValidationFeature(string $filename, string $mime, int $err_code,
        array $options, bool $is_error, string $message = '')
    {
        $_FILES['file'] = getTestFileDetails($filename, $mime, $err_code);
        $this->_validator->validateFile(true, 'file', $filename, $options);

        if ($is_error)
        {
            $this->assertFalse($this->_validator->succeeds());
            $this->assertEquals($message, $this->_validator->getError('file'));
        }
        else
        {
            $this->assertTrue($this->_validator->succeeds());
        }
    }

    /**
     * test file upload error validation feature
     *@dataProvider fileUploadErrorDataProvider
    */
    public function testFileUploadErrorValidationFeature(int $err_code, string $message)
    {
        $this->runFileValidationFeature(
            'file1.jpg',
            'image/jpeg',
            $err_code,
            [],
            true,
            $message
        );
    }

    /**
     * test file size limit rule validation
     *@dataProvider fileSizeLimitDataProvider
    */
    public function testFileSizeLimitingRules(array $options, bool $is_error,
        string $message = '')
    {
        $this->runFileValidationFeature(
            'file1.jpg',
            'image/jpeg',
            UPLOAD_ERR_OK,
            $options,
            $is_error,
            $message
        );
    }

    /**
     * test file extension validation
     *@dataProvider fileExtensionTestDataProvider
    */
    public function testFileExtensionValidation(string $filename, string $mime, array $options,
        bool $is_error, string $message = '')
    {
        $this->runFileValidationFeature(
            $filename,
            $mime,
            UPLOAD_ERR_OK,
            $options,
            $is_error,
            $message
        );
    }

    /**
     * test that the file validator throws DirectoryNotFoundException if the moveTo folder
     * does not exists
    */
    public function testFileMoveDirectoryNotFoundException()
    {
        $_FILES['file'] = getTestFileDetails('file1.jpg', 'image.jpeg');

        $this->expectException(DirectoryNotFoundException::class);
        $this->_validator->validateFile(true, 'file', 'file1.jpg', [
            'moveTo' => 'tests/unknown'
        ]);
    }

    /**
     * test that the file validator sets appropriate error if uploaded file could not be moved
    */
    public function testFileMoveWritePermissionDenial()
    {
        $_FILES['file'] = getTestFileDetails('file1.jpg', 'image.jpeg');

        $this->expectException(FileMoveException::class);
        $this->_validator->validateFile(true, 'file', 'file1.jpg', [
            'moveTo' => '/root'
        ]);
    }

    /**
     * test that the file validator successfully moves the file if the moveTo exists and if the
     * appropriate write permission exist. test also that the $new_value reference parameter
     * is set accordingly to the files computed hash
    */
    public function testSuccessfulFileMove()
    {
        $_FILES['file'] = getTestFileDetails('file1.jpg', 'image.jpeg');

        $new_value = '';
        $this->_validator->validateFile(true, 'file', 'file1.jpg', [
            'moveTo' => 'tests/Helpers'
        ], 0, $new_value);

        $this->assertNotEquals('', $new_value);
        $this->assertFileExists('tests/Helpers/' . $new_value);

        rename('tests/Helpers/' . $new_value, 'tests/Helpers/file1.jpg');
    }

    /**
     *@dataProvider fileVarietyMethodsTestDataProvider
    */
    public function testFileVarietyMethods(string $method, string $filename,
        string $mime, bool $is_error = false, $err = '')
    {
        $_FILES['file'] = getTestFileDetails($filename, $mime);

        $this->_validator->{$method}(true, 'file', $filename, [], 0, $new_value);
        if ($is_error)
        {
            $this->assertFalse($this->_validator->succeeds());
            $this->assertEquals($err, $this->_validator->getError('file'));
        }
        else
        {
            $this->assertTrue($this->_validator->succeeds());
        }
    }

    /**
     * test that limiting values are resolved accurately
     *@dataProvider limitingValueResolutionTestDataProvider
    */
    public function testLimitingValueResolution(string $field, $value, array $option,
        string $err)
    {
        $this->_validator->validateText(true, $field, $value, $option);
        $this->assertFalse($this->_validator->succeeds());
        $this->assertEquals($err, $this->_validator->getError());
    }
}