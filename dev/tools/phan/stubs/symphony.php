<?php

namespace Symfony\Component\Validator\Command;

/**
 * A console command to debug Validators information.
 *
 * @author Loïc Frémont <lc.fremont@gmail.com>
 */
class DebugCommand extends \Symfony\Component\Console\Command\Command
{
	protected static $defaultName = 'debug:validator';
	protected static $defaultDescription = 'Display validation constraints for classes';
	public function __construct(\Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface $validator)
	{
	}
	protected function configure()
	{
	}
	protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
	{
	}
}

namespace Symfony\Component\Validator;

/**
 * Contains the properties of a constraint definition.
 *
 * A constraint can be defined on a class, a property or a getter method.
 * The Constraint class encapsulates all the configuration required for
 * validating this class, property or getter result successfully.
 *
 * Constraint instances are immutable and serializable.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
abstract class Constraint
{
	/**
	 * The name of the group given to all constraints with no explicit group.
	 */
	public const DEFAULT_GROUP = 'Default';
	/**
	 * Marks a constraint that can be put onto classes.
	 */
	public const CLASS_CONSTRAINT = 'class';
	/**
	 * Marks a constraint that can be put onto properties.
	 */
	public const PROPERTY_CONSTRAINT = 'property';
	/**
	 * Maps error codes to the names of their constants.
	 */
	protected static $errorNames = [];
	/**
	 * Domain-specific data attached to a constraint.
	 *
	 * @var mixed
	 */
	public $payload;
	/**
	 * The groups that the constraint belongs to.
	 *
	 * @var string[]
	 */
	public $groups;
	/**
	 * Returns the name of the given error code.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException If the error code does not exist
	 */
	public static function getErrorName(string $errorCode)
	{
	}
	/**
	 * Initializes the constraint with options.
	 *
	 * You should pass an associative array. The keys should be the names of
	 * existing properties in this class. The values should be the value for these
	 * properties.
	 *
	 * Alternatively you can override the method getDefaultOption() to return the
	 * name of an existing property. If no associative array is passed, this
	 * property is set instead.
	 *
	 * You can force that certain options are set by overriding
	 * getRequiredOptions() to return the names of these options. If any
	 * option is not set here, an exception is thrown.
	 *
	 * @param mixed    $options The options (as associative array)
	 *                          or the value for the default
	 *                          option (any other type)
	 * @param string[] $groups  An array of validation groups
	 * @param mixed    $payload Domain-specific data attached to a constraint
	 *
	 * @throws InvalidOptionsException       When you pass the names of non-existing
	 *                                       options
	 * @throws MissingOptionsException       When you don't pass any of the options
	 *                                       returned by getRequiredOptions()
	 * @throws ConstraintDefinitionException When you don't pass an associative
	 *                                       array, but getDefaultOption() returns
	 *                                       null
	 */
	public function __construct($options = null, ?array $groups = null, $payload = null)
	{
	}
	protected function normalizeOptions($options): array
	{
	}
	/**
	 * Sets the value of a lazily initialized option.
	 *
	 * Corresponding properties are added to the object on first access. Hence
	 * this method will be called at most once per constraint instance and
	 * option name.
	 *
	 * @param mixed $value The value to set
	 *
	 * @throws InvalidOptionsException If an invalid option name is given
	 */
	public function __set(string $option, $value)
	{
	}
	/**
	 * Returns the value of a lazily initialized option.
	 *
	 * Corresponding properties are added to the object on first access. Hence
	 * this method will be called at most once per constraint instance and
	 * option name.
	 *
	 * @return mixed
	 *
	 * @throws InvalidOptionsException If an invalid option name is given
	 */
	public function __get(string $option)
	{
	}
	/**
	 * @return bool
	 */
	public function __isset(string $option)
	{
	}
	/**
	 * Adds the given group if this constraint is in the Default group.
	 */
	public function addImplicitGroupName(string $group)
	{
	}
	/**
	 * Returns the name of the default option.
	 *
	 * Override this method to define a default option.
	 *
	 * @return string|null
	 *
	 * @see __construct()
	 */
	public function getDefaultOption()
	{
	}
	/**
	 * Returns the name of the required options.
	 *
	 * Override this method if you want to define required options.
	 *
	 * @return string[]
	 *
	 * @see __construct()
	 */
	public function getRequiredOptions()
	{
	}
	/**
	 * Returns the name of the class that validates this constraint.
	 *
	 * By default, this is the fully qualified name of the constraint class
	 * suffixed with "Validator". You can override this method to change that
	 * behavior.
	 *
	 * @return string
	 */
	public function validatedBy()
	{
	}
	/**
	 * Returns whether the constraint can be put onto classes, properties or
	 * both.
	 *
	 * This method should return one or more of the constants
	 * Constraint::CLASS_CONSTRAINT and Constraint::PROPERTY_CONSTRAINT.
	 *
	 * @return string|string[] One or more constant values
	 */
	public function getTargets()
	{
	}
	/**
	 * Optimizes the serialized value to minimize storage space.
	 *
	 * @internal
	 */
	public function __sleep(): array
	{
	}
}

namespace Symfony\Component\Validator\Constraints;

/**
 * Used for the comparison of values.
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
abstract class AbstractComparison extends \Symfony\Component\Validator\Constraint
{
	public $message;
	public $value;
	public $propertyPath;
	/**
	 * {@inheritdoc}
	 *
	 * @param mixed $value the value to compare or a set of options
	 */
	public function __construct($value = null, $propertyPath = null, ?string $message = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getDefaultOption()
	{
	}
}

namespace Symfony\Component\Validator;

/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ConstraintValidatorInterface
{
	/**
	 * Initializes the constraint validator.
	 */
	public function initialize(\Symfony\Component\Validator\Context\ExecutionContextInterface $context);
	/**
	 * Checks if the passed value is valid.
	 *
	 * @param mixed $value The value that should be validated
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint);
}
/**
 * Base class for constraint validators.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
abstract class ConstraintValidator implements \Symfony\Component\Validator\ConstraintValidatorInterface
{
	/**
	 * Whether to format {@link \DateTime} objects, either with the {@link \IntlDateFormatter}
	 * (if it is available) or as RFC-3339 dates ("Y-m-d H:i:s").
	 */
	public const PRETTY_DATE = 1;
	/**
	 * Whether to cast objects with a "__toString()" method to strings.
	 */
	public const OBJECT_TO_STRING = 2;
	/**
	 * @var ExecutionContextInterface
	 */
	protected $context;
	/**
	 * {@inheritdoc}
	 */
	public function initialize(\Symfony\Component\Validator\Context\ExecutionContextInterface $context)
	{
	}
	/**
	 * Returns a string representation of the type of the value.
	 *
	 * This method should be used if you pass the type of a value as
	 * message parameter to a constraint violation. Note that such
	 * parameters should usually not be included in messages aimed at
	 * non-technical people.
	 *
	 * @param mixed $value The value to return the type of
	 *
	 * @return string
	 */
	protected function formatTypeOf($value)
	{
	}
	/**
	 * Returns a string representation of the value.
	 *
	 * This method returns the equivalent PHP tokens for most scalar types
	 * (i.e. "false" for false, "1" for 1 etc.). Strings are always wrapped
	 * in double quotes ("). Objects, arrays and resources are formatted as
	 * "object", "array" and "resource". If the $format bitmask contains
	 * the PRETTY_DATE bit, then {@link \DateTime} objects will be formatted
	 * with the {@link \IntlDateFormatter}. If it is not available, they will be
	 * formatted as RFC-3339 dates ("Y-m-d H:i:s").
	 *
	 * Be careful when passing message parameters to a constraint violation
	 * that (may) contain objects, arrays or resources. These parameters
	 * should only be displayed for technical users. Non-technical users
	 * won't know what an "object", "array" or "resource" is and will be
	 * confused by the violation message.
	 *
	 * @param mixed $value  The value to format as string
	 * @param int   $format A bitwise combination of the format
	 *                      constants in this class
	 *
	 * @return string
	 */
	protected function formatValue($value, int $format = 0)
	{
	}
	/**
	 * Returns a string representation of a list of values.
	 *
	 * Each of the values is converted to a string using
	 * {@link formatValue()}. The values are then concatenated with commas.
	 *
	 * @param array $values A list of values
	 * @param int   $format A bitwise combination of the format
	 *                      constants in this class
	 *
	 * @return string
	 *
	 * @see formatValue()
	 */
	protected function formatValues(array $values, int $format = 0)
	{
	}
}

namespace Symfony\Component\Validator\Constraints;

/**
 * Provides a base class for the validation of property comparisons.
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
abstract class AbstractComparisonValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function __construct(?\Symfony\Component\PropertyAccess\PropertyAccessorInterface $propertyAccessor = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
	/**
	 * Compares the two given values to find if their relationship is valid.
	 *
	 * @param mixed $value1 The first value to compare
	 * @param mixed $value2 The second value to compare
	 *
	 * @return bool
	 */
	abstract protected function compareValues($value1, $value2);
	/**
	 * Returns the error code used if the comparison fails.
	 *
	 * @return string|null
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * A constraint that is composed of other constraints.
 *
 * You should never use the nested constraint instances anywhere else, because
 * their groups are adapted when passed to the constructor of this class.
 *
 * If you want to create your own composite constraint, extend this class and
 * let {@link getCompositeOption()} return the name of the property which
 * contains the nested constraints.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
abstract class Composite extends \Symfony\Component\Validator\Constraint
{
	/**
	 * {@inheritdoc}
	 *
	 * The groups of the composite and its nested constraints are made
	 * consistent using the following strategy:
	 *
	 *   - If groups are passed explicitly to the composite constraint, but
	 *     not to the nested constraints, the options of the composite
	 *     constraint are copied to the nested constraints;
	 *
	 *   - If groups are passed explicitly to the nested constraints, but not
	 *     to the composite constraint, the groups of all nested constraints
	 *     are merged and used as groups for the composite constraint;
	 *
	 *   - If groups are passed explicitly to both the composite and its nested
	 *     constraints, the groups of the nested constraints must be a subset
	 *     of the groups of the composite constraint. If not, a
	 *     {@link ConstraintDefinitionException} is thrown.
	 *
	 * All this is done in the constructor, because constraints can then be
	 * cached. When constraints are loaded from the cache, no more group
	 * checks need to be done.
	 */
	public function __construct($options = null, ?array $groups = null, $payload = null)
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * Implicit group names are forwarded to nested constraints.
	 */
	public function addImplicitGroupName(string $group)
	{
	}
	/**
	 * Returns the name of the property that contains the nested constraints.
	 *
	 * @return string
	 */
	abstract protected function getCompositeOption();
	/**
	 * @internal Used by metadata
	 *
	 * @return Constraint[]
	 */
	public function getNestedConstraints()
	{
	}
	/**
	 * Initializes the nested constraints.
	 *
	 * This method can be overwritten in subclasses to clean up the nested
	 * constraints passed to the constructor.
	 *
	 * @see Collection::initializeNestedConstraints()
	 */
	protected function initializeNestedConstraints()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class All extends \Symfony\Component\Validator\Constraints\Composite
{
	public $constraints = [];
	public function __construct($constraints = null, ?array $groups = null, $payload = null)
	{
	}
	public function getDefaultOption()
	{
	}
	public function getRequiredOptions()
	{
	}
	protected function getCompositeOption()
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class AllValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Przemysław Bogusz <przemyslaw.bogusz@tubotax.pl>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class AtLeastOneOf extends \Symfony\Component\Validator\Constraints\Composite
{
	public const AT_LEAST_ONE_OF_ERROR = 'f27e6d6c-261a-4056-b391-6673a623531c';
	protected static $errorNames = [self::AT_LEAST_ONE_OF_ERROR => 'AT_LEAST_ONE_OF_ERROR'];
	public $constraints = [];
	public $message = 'This value should satisfy at least one of the following constraints:';
	public $messageCollection = 'Each element of this collection should satisfy its own set of constraints.';
	public $includeInternalMessages = true;
	public function __construct($constraints = null, ?array $groups = null, $payload = null, ?string $message = null, ?string $messageCollection = null, ?bool $includeInternalMessages = null)
	{
	}
	public function getDefaultOption()
	{
	}
	public function getRequiredOptions()
	{
	}
	protected function getCompositeOption()
	{
	}
}
/**
 * @author Przemysław Bogusz <przemyslaw.bogusz@tubotax.pl>
 */
class AtLeastOneOfValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Michael Hirschler <michael.vhirsch@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Bic extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_LENGTH_ERROR = '66dad313-af0b-4214-8566-6c799be9789c';
	public const INVALID_CHARACTERS_ERROR = 'f424c529-7add-4417-8f2d-4b656e4833e2';
	public const INVALID_BANK_CODE_ERROR = '00559357-6170-4f29-aebd-d19330aa19cf';
	public const INVALID_COUNTRY_CODE_ERROR = '1ce76f8d-3c1f-451c-9e62-fe9c3ed486ae';
	public const INVALID_CASE_ERROR = '11884038-3312-4ae5-9d04-699f782130c7';
	public const INVALID_IBAN_COUNTRY_CODE_ERROR = '29a2c3bb-587b-4996-b6f5-53081364cea5';
	protected static $errorNames = [self::INVALID_LENGTH_ERROR => 'INVALID_LENGTH_ERROR', self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR', self::INVALID_BANK_CODE_ERROR => 'INVALID_BANK_CODE_ERROR', self::INVALID_COUNTRY_CODE_ERROR => 'INVALID_COUNTRY_CODE_ERROR', self::INVALID_CASE_ERROR => 'INVALID_CASE_ERROR'];
	public $message = 'This is not a valid Business Identifier Code (BIC).';
	public $ibanMessage = 'This Business Identifier Code (BIC) is not associated with IBAN {{ iban }}.';
	public $iban;
	public $ibanPropertyPath;
	/**
	 * {@inheritdoc}
	 *
	 * @param string|PropertyPathInterface|null $ibanPropertyPath
	 */
	public function __construct(?array $options = null, ?string $message = null, ?string $iban = null, $ibanPropertyPath = null, ?string $ibanMessage = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Michael Hirschler <michael.vhirsch@gmail.com>
 *
 * @see https://en.wikipedia.org/wiki/ISO_9362#Structure
 */
class BicValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function __construct(?\Symfony\Component\PropertyAccess\PropertyAccessor $propertyAccessor = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Blank extends \Symfony\Component\Validator\Constraint
{
	public const NOT_BLANK_ERROR = '183ad2de-533d-4796-a439-6d3c3852b549';
	protected static $errorNames = [self::NOT_BLANK_ERROR => 'NOT_BLANK_ERROR'];
	public $message = 'This value should be blank.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class BlankValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"CLASS", "PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Callback extends \Symfony\Component\Validator\Constraint
{
	/**
	 * @var string|callable
	 */
	public $callback;
	/**
	 * {@inheritdoc}
	 *
	 * @param array|string|callable $callback The callback or a set of options
	 */
	public function __construct($callback = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getDefaultOption()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getTargets()
	{
	}
}
/**
 * Validator for Callback constraint.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class CallbackValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($object, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * Metadata for the CardSchemeValidator.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Tim Nagel <t.nagel@infinite.net.au>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class CardScheme extends \Symfony\Component\Validator\Constraint
{
	public const AMEX = 'AMEX';
	public const CHINA_UNIONPAY = 'CHINA_UNIONPAY';
	public const DINERS = 'DINERS';
	public const DISCOVER = 'DISCOVER';
	public const INSTAPAYMENT = 'INSTAPAYMENT';
	public const JCB = 'JCB';
	public const LASER = 'LASER';
	public const MAESTRO = 'MAESTRO';
	public const MASTERCARD = 'MASTERCARD';
	public const MIR = 'MIR';
	public const UATP = 'UATP';
	public const VISA = 'VISA';
	public const NOT_NUMERIC_ERROR = 'a2ad9231-e827-485f-8a1e-ef4d9a6d5c2e';
	public const INVALID_FORMAT_ERROR = 'a8faedbf-1c2f-4695-8d22-55783be8efed';
	protected static $errorNames = [self::NOT_NUMERIC_ERROR => 'NOT_NUMERIC_ERROR', self::INVALID_FORMAT_ERROR => 'INVALID_FORMAT_ERROR'];
	public $message = 'Unsupported card type or invalid card number.';
	public $schemes;
	/**
	 * {@inheritdoc}
	 *
	 * @param array|string $schemes The schemes to validate against or a set of options
	 */
	public function __construct($schemes, ?string $message = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
	public function getDefaultOption()
	{
	}
	public function getRequiredOptions()
	{
	}
}
/**
 * Validates that a card number belongs to a specified scheme.
 *
 * @author Tim Nagel <t.nagel@infinite.net.au>
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see https://en.wikipedia.org/wiki/Payment_card_number
 * @see https://www.regular-expressions.info/creditcard.html
 */
class CardSchemeValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	protected $schemes = [
		// American Express card numbers start with 34 or 37 and have 15 digits.
		\Symfony\Component\Validator\Constraints\CardScheme::AMEX => ['/^3[47][0-9]{13}$/D'],
		// China UnionPay cards start with 62 and have between 16 and 19 digits.
		// Please note that these cards do not follow Luhn Algorithm as a checksum.
		\Symfony\Component\Validator\Constraints\CardScheme::CHINA_UNIONPAY => ['/^62[0-9]{14,17}$/D'],
		// Diners Club card numbers begin with 300 through 305, 36 or 38. All have 14 digits.
		// There are Diners Club cards that begin with 5 and have 16 digits.
		// These are a joint venture between Diners Club and MasterCard, and should be processed like a MasterCard.
		\Symfony\Component\Validator\Constraints\CardScheme::DINERS => ['/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/D'],
		// Discover card numbers begin with 6011, 622126 through 622925, 644 through 649 or 65.
		// All have 16 digits.
		\Symfony\Component\Validator\Constraints\CardScheme::DISCOVER => ['/^6011[0-9]{12}$/D', '/^64[4-9][0-9]{13}$/D', '/^65[0-9]{14}$/D', '/^622(12[6-9]|1[3-9][0-9]|[2-8][0-9][0-9]|91[0-9]|92[0-5])[0-9]{10}$/D'],
		// InstaPayment cards begin with 637 through 639 and have 16 digits.
		\Symfony\Component\Validator\Constraints\CardScheme::INSTAPAYMENT => ['/^63[7-9][0-9]{13}$/D'],
		// JCB cards beginning with 2131 or 1800 have 15 digits.
		// JCB cards beginning with 35 have 16 digits.
		\Symfony\Component\Validator\Constraints\CardScheme::JCB => ['/^(?:2131|1800|35[0-9]{3})[0-9]{11}$/D'],
		// Laser cards begin with either 6304, 6706, 6709 or 6771 and have between 16 and 19 digits.
		\Symfony\Component\Validator\Constraints\CardScheme::LASER => ['/^(6304|670[69]|6771)[0-9]{12,15}$/D'],
		// Maestro international cards begin with 675900..675999 and have between 12 and 19 digits.
		// Maestro UK cards begin with either 500000..509999 or 560000..699999 and have between 12 and 19 digits.
		\Symfony\Component\Validator\Constraints\CardScheme::MAESTRO => ['/^(6759[0-9]{2})[0-9]{6,13}$/D', '/^(50[0-9]{4})[0-9]{6,13}$/D', '/^5[6-9][0-9]{10,17}$/D', '/^6[0-9]{11,18}$/D'],
		// All MasterCard numbers start with the numbers 51 through 55. All have 16 digits.
		// October 2016 MasterCard numbers can also start with 222100 through 272099.
		\Symfony\Component\Validator\Constraints\CardScheme::MASTERCARD => ['/^5[1-5][0-9]{14}$/D', '/^2(22[1-9][0-9]{12}|2[3-9][0-9]{13}|[3-6][0-9]{14}|7[0-1][0-9]{13}|720[0-9]{12})$/D'],
		// Payment system MIR numbers start with 220, then 1 digit from 0 to 4, then between 12 and 15 digits
		\Symfony\Component\Validator\Constraints\CardScheme::MIR => ['/^220[0-4][0-9]{12,15}$/D'],
		// All UATP card numbers start with a 1 and have a length of 15 digits.
		\Symfony\Component\Validator\Constraints\CardScheme::UATP => ['/^1[0-9]{14}$/D'],
		// All Visa card numbers start with a 4 and have a length of 13, 16, or 19 digits.
		\Symfony\Component\Validator\Constraints\CardScheme::VISA => ['/^4([0-9]{12}|[0-9]{15}|[0-9]{18})$/D'],
	];
	/**
	 * Validates a creditcard belongs to a specified scheme.
	 *
	 * @param mixed $value
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"CLASS"})
 *
 * @author Jules Pietri <jules@heahprod.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Cascade extends \Symfony\Component\Validator\Constraint
{
	public function __construct(?array $options = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getTargets()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Choice extends \Symfony\Component\Validator\Constraint
{
	public const NO_SUCH_CHOICE_ERROR = '8e179f1b-97aa-4560-a02f-2a8b42e49df7';
	public const TOO_FEW_ERROR = '11edd7eb-5872-4b6e-9f12-89923999fd0e';
	public const TOO_MANY_ERROR = '9bd98e49-211c-433f-8630-fd1c2d0f08c3';
	protected static $errorNames = [self::NO_SUCH_CHOICE_ERROR => 'NO_SUCH_CHOICE_ERROR', self::TOO_FEW_ERROR => 'TOO_FEW_ERROR', self::TOO_MANY_ERROR => 'TOO_MANY_ERROR'];
	public $choices;
	public $callback;
	public $multiple = false;
	public $strict = true;
	public $min;
	public $max;
	public $message = 'The value you selected is not a valid choice.';
	public $multipleMessage = 'One or more of the given values is invalid.';
	public $minMessage = 'You must select at least {{ limit }} choice.|You must select at least {{ limit }} choices.';
	public $maxMessage = 'You must select at most {{ limit }} choice.|You must select at most {{ limit }} choices.';
	/**
	 * {@inheritdoc}
	 */
	public function getDefaultOption()
	{
	}
	public function __construct($options = [], ?array $choices = null, $callback = null, ?bool $multiple = null, ?bool $strict = null, ?int $min = null, ?int $max = null, ?string $message = null, ?string $multipleMessage = null, ?string $minMessage = null, ?string $maxMessage = null, $groups = null, $payload = null)
	{
	}
}
/**
 * ChoiceValidator validates that the value is one of the expected values.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Florian Eckerstorfer <florian@eckerstorfer.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ChoiceValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * Validates that a value is a valid CIDR notation.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Sorin Pop <popsorin15@gmail.com>
 * @author Calin Bolea <calin.bolea@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Cidr extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_CIDR_ERROR = '5649e53a-5afb-47c5-a360-ffbab3be8567';
	public const OUT_OF_RANGE_ERROR = 'b9f14a51-acbd-401a-a078-8c6b204ab32f';
	protected static $errorNames = [self::INVALID_CIDR_ERROR => 'INVALID_CIDR_ERROR', self::OUT_OF_RANGE_ERROR => 'OUT_OF_RANGE_VIOLATION'];
	public $version = \Symfony\Component\Validator\Constraints\Ip::ALL;
	public $message = 'This value is not a valid CIDR notation.';
	public $netmaskRangeViolationMessage = 'The value of the netmask should be between {{ min }} and {{ max }}.';
	public $netmaskMin = 0;
	public $netmaskMax;
	public function __construct(?array $options = null, ?string $version = null, ?int $netmaskMin = null, ?int $netmaskMax = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
class CidrValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint): void
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Collection extends \Symfony\Component\Validator\Constraints\Composite
{
	public const MISSING_FIELD_ERROR = '2fa2158c-2a7f-484b-98aa-975522539ff8';
	public const NO_SUCH_FIELD_ERROR = '7703c766-b5d5-4cef-ace7-ae0dd82304e9';
	protected static $errorNames = [self::MISSING_FIELD_ERROR => 'MISSING_FIELD_ERROR', self::NO_SUCH_FIELD_ERROR => 'NO_SUCH_FIELD_ERROR'];
	public $fields = [];
	public $allowExtraFields = false;
	public $allowMissingFields = false;
	public $extraFieldsMessage = 'This field was not expected.';
	public $missingFieldsMessage = 'This field is missing.';
	/**
	 * {@inheritdoc}
	 */
	public function __construct($fields = null, ?array $groups = null, $payload = null, ?bool $allowExtraFields = null, ?bool $allowMissingFields = null, ?string $extraFieldsMessage = null, ?string $missingFieldsMessage = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function initializeNestedConstraints()
	{
	}
	public function getRequiredOptions()
	{
	}
	protected function getCompositeOption()
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class CollectionValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * Extend this class to create a reusable set of constraints.
 *
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
abstract class Compound extends \Symfony\Component\Validator\Constraints\Composite
{
	/** @var Constraint[] */
	public $constraints = [];
	public function __construct($options = null)
	{
	}
	final protected function getCompositeOption(): string
	{
	}
	final public function validatedBy(): string
	{
	}
	/**
	 * @return Constraint[]
	 */
	abstract protected function getConstraints(array $options): array;
}
/**
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
class CompoundValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Count extends \Symfony\Component\Validator\Constraint
{
	public const TOO_FEW_ERROR = 'bef8e338-6ae5-4caf-b8e2-50e7b0579e69';
	public const TOO_MANY_ERROR = '756b1212-697c-468d-a9ad-50dd783bb169';
	public const NOT_EQUAL_COUNT_ERROR = '9fe5d43f-3784-4ece-a0e1-473fc02dadbc';
	public const NOT_DIVISIBLE_BY_ERROR = \Symfony\Component\Validator\Constraints\DivisibleBy::NOT_DIVISIBLE_BY;
	protected static $errorNames = [self::TOO_FEW_ERROR => 'TOO_FEW_ERROR', self::TOO_MANY_ERROR => 'TOO_MANY_ERROR', self::NOT_EQUAL_COUNT_ERROR => 'NOT_EQUAL_COUNT_ERROR', self::NOT_DIVISIBLE_BY_ERROR => 'NOT_DIVISIBLE_BY_ERROR'];
	public $minMessage = 'This collection should contain {{ limit }} element or more.|This collection should contain {{ limit }} elements or more.';
	public $maxMessage = 'This collection should contain {{ limit }} element or less.|This collection should contain {{ limit }} elements or less.';
	public $exactMessage = 'This collection should contain exactly {{ limit }} element.|This collection should contain exactly {{ limit }} elements.';
	public $divisibleByMessage = 'The number of elements in this collection should be a multiple of {{ compared_value }}.';
	public $min;
	public $max;
	public $divisibleBy;
	/**
	 * {@inheritdoc}
	 *
	 * @param int|array|null $exactly The expected exact count or a set of options
	 */
	public function __construct($exactly = null, ?int $min = null, ?int $max = null, ?int $divisibleBy = null, ?string $exactMessage = null, ?string $minMessage = null, ?string $maxMessage = null, ?string $divisibleByMessage = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Country extends \Symfony\Component\Validator\Constraint
{
	public const NO_SUCH_COUNTRY_ERROR = '8f900c12-61bd-455d-9398-996cd040f7f0';
	protected static $errorNames = [self::NO_SUCH_COUNTRY_ERROR => 'NO_SUCH_COUNTRY_ERROR'];
	public $message = 'This value is not a valid country.';
	public $alpha3 = false;
	public function __construct(?array $options = null, ?string $message = null, ?bool $alpha3 = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates whether a value is a valid country code.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class CountryValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class CountValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class CssColor extends \Symfony\Component\Validator\Constraint
{
	public const HEX_LONG = 'hex_long';
	public const HEX_LONG_WITH_ALPHA = 'hex_long_with_alpha';
	public const HEX_SHORT = 'hex_short';
	public const HEX_SHORT_WITH_ALPHA = 'hex_short_with_alpha';
	public const BASIC_NAMED_COLORS = 'basic_named_colors';
	public const EXTENDED_NAMED_COLORS = 'extended_named_colors';
	public const SYSTEM_COLORS = 'system_colors';
	public const KEYWORDS = 'keywords';
	public const RGB = 'rgb';
	public const RGBA = 'rgba';
	public const HSL = 'hsl';
	public const HSLA = 'hsla';
	public const INVALID_FORMAT_ERROR = '454ab47b-aacf-4059-8f26-184b2dc9d48d';
	protected static $errorNames = [self::INVALID_FORMAT_ERROR => 'INVALID_FORMAT_ERROR'];
	public $message = 'This value is not a valid CSS color.';
	public $formats;
	/**
	 * @param array|string $formats The types of CSS colors allowed (e.g. hexadecimal only, RGB and HSL only, etc.).
	 */
	public function __construct($formats = [], ?string $message = null, ?array $groups = null, $payload = null, ?array $options = null)
	{
	}
	public function getDefaultOption(): string
	{
	}
	public function getRequiredOptions(): array
	{
	}
}
/**
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
class CssColorValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint): void
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Miha Vrhovnik <miha.vrhovnik@pagein.si>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Currency extends \Symfony\Component\Validator\Constraint
{
	public const NO_SUCH_CURRENCY_ERROR = '69945ac1-2db4-405f-bec7-d2772f73df52';
	protected static $errorNames = [self::NO_SUCH_CURRENCY_ERROR => 'NO_SUCH_CURRENCY_ERROR'];
	public $message = 'This value is not a valid currency.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates whether a value is a valid currency.
 *
 * @author Miha Vrhovnik <miha.vrhovnik@pagein.si>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class CurrencyValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Date extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_FORMAT_ERROR = '69819696-02ac-4a99-9ff0-14e127c4d1bc';
	public const INVALID_DATE_ERROR = '3c184ce5-b31d-4de7-8b76-326da7b2be93';
	protected static $errorNames = [self::INVALID_FORMAT_ERROR => 'INVALID_FORMAT_ERROR', self::INVALID_DATE_ERROR => 'INVALID_DATE_ERROR'];
	public $message = 'This value is not a valid date.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class DateTime extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_FORMAT_ERROR = '1a9da513-2640-4f84-9b6a-4d99dcddc628';
	public const INVALID_DATE_ERROR = 'd52afa47-620d-4d99-9f08-f4d85b36e33c';
	public const INVALID_TIME_ERROR = '5e797c9d-74f7-4098-baa3-94390c447b27';
	protected static $errorNames = [self::INVALID_FORMAT_ERROR => 'INVALID_FORMAT_ERROR', self::INVALID_DATE_ERROR => 'INVALID_DATE_ERROR', self::INVALID_TIME_ERROR => 'INVALID_TIME_ERROR'];
	public $format = 'Y-m-d H:i:s';
	public $message = 'This value is not a valid datetime.';
	/**
	 * {@inheritdoc}
	 *
	 * @param string|array|null $format
	 */
	public function __construct($format = null, ?string $message = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
	public function getDefaultOption()
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class DateValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public const PATTERN = '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/D';
	/**
	 * Checks whether a date is valid.
	 *
	 * @internal
	 */
	public static function checkDate(int $year, int $month, int $day): bool
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Diego Saint Esteben <diego@saintesteben.me>
 */
class DateTimeValidator extends \Symfony\Component\Validator\Constraints\DateValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * Disables auto mapping.
 *
 * Using the annotations on a property has higher precedence than using it on a class,
 * which has higher precedence than any configuration that might be defined outside the class.
 *
 * @Annotation
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
class DisableAutoMapping extends \Symfony\Component\Validator\Constraint
{
	public function __construct(?array $options = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getTargets()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Colin O'Dell <colinodell@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class DivisibleBy extends \Symfony\Component\Validator\Constraints\AbstractComparison
{
	public const NOT_DIVISIBLE_BY = '6d99d6c3-1464-4ccf-bdc7-14d083cf455c';
	protected static $errorNames = [self::NOT_DIVISIBLE_BY => 'NOT_DIVISIBLE_BY'];
	public $message = 'This value should be a multiple of {{ compared_value }}.';
}
/**
 * Validates that values are a multiple of the given number.
 *
 * @author Colin O'Dell <colinodell@gmail.com>
 */
class DivisibleByValidator extends \Symfony\Component\Validator\Constraints\AbstractComparisonValidator
{
	/**
	 * {@inheritdoc}
	 */
	protected function compareValues($value1, $value2)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Email extends \Symfony\Component\Validator\Constraint
{
	public const VALIDATION_MODE_HTML5 = 'html5';
	public const VALIDATION_MODE_STRICT = 'strict';
	public const VALIDATION_MODE_LOOSE = 'loose';
	public const INVALID_FORMAT_ERROR = 'bd79c0ab-ddba-46cc-a703-a7a4b08de310';
	protected static $errorNames = [self::INVALID_FORMAT_ERROR => 'INVALID_FORMAT_ERROR'];
	/**
	 * @var string[]
	 *
	 * @internal
	 */
	public static $validationModes = [self::VALIDATION_MODE_HTML5, self::VALIDATION_MODE_STRICT, self::VALIDATION_MODE_LOOSE];
	public $message = 'This value is not a valid email address.';
	public $mode;
	public $normalizer;
	public function __construct(?array $options = null, ?string $message = null, ?string $mode = null, ?callable $normalizer = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class EmailValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function __construct(string $defaultMode = \Symfony\Component\Validator\Constraints\Email::VALIDATION_MODE_LOOSE)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * Enables auto mapping.
 *
 * Using the annotations on a property has higher precedence than using it on a class,
 * which has higher precedence than any configuration that might be defined outside the class.
 *
 * @Annotation
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
class EnableAutoMapping extends \Symfony\Component\Validator\Constraint
{
	public function __construct(?array $options = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getTargets()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class EqualTo extends \Symfony\Component\Validator\Constraints\AbstractComparison
{
	public const NOT_EQUAL_ERROR = '478618a7-95ba-473d-9101-cabd45e49115';
	protected static $errorNames = [self::NOT_EQUAL_ERROR => 'NOT_EQUAL_ERROR'];
	public $message = 'This value should be equal to {{ compared_value }}.';
}
/**
 * Validates values are equal (==).
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class EqualToValidator extends \Symfony\Component\Validator\Constraints\AbstractComparisonValidator
{
	/**
	 * {@inheritdoc}
	 */
	protected function compareValues($value1, $value2)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
abstract class Existence extends \Symfony\Component\Validator\Constraints\Composite
{
	public $constraints = [];
	public function getDefaultOption()
	{
	}
	protected function getCompositeOption()
	{
	}
}
/**
 * @Annotation
 * @Target({"CLASS", "PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Expression extends \Symfony\Component\Validator\Constraint
{
	public const EXPRESSION_FAILED_ERROR = '6b3befbc-2f01-4ddf-be21-b57898905284';
	protected static $errorNames = [self::EXPRESSION_FAILED_ERROR => 'EXPRESSION_FAILED_ERROR'];
	public $message = 'This value is not valid.';
	public $expression;
	public $values = [];
	/**
	 * {@inheritdoc}
	 *
	 * @param string|ExpressionObject|array $expression The expression to evaluate or an array of options
	 */
	public function __construct($expression, ?string $message = null, ?array $values = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getDefaultOption()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getRequiredOptions()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getTargets()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validatedBy()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Andrey Sevastianov <mrpkmail@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ExpressionLanguageSyntax extends \Symfony\Component\Validator\Constraint
{
	public const EXPRESSION_LANGUAGE_SYNTAX_ERROR = '1766a3f3-ff03-40eb-b053-ab7aa23d988a';
	protected static $errorNames = [self::EXPRESSION_LANGUAGE_SYNTAX_ERROR => 'EXPRESSION_LANGUAGE_SYNTAX_ERROR'];
	public $message = 'This value should be a valid expression.';
	public $service;
	public $allowedVariables;
	public function __construct(?array $options = null, ?string $message = null, ?string $service = null, ?array $allowedVariables = null, ?array $groups = null, $payload = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validatedBy()
	{
	}
}
/**
 * @author Andrey Sevastianov <mrpkmail@gmail.com>
 */
class ExpressionLanguageSyntaxValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function __construct(?\Symfony\Component\ExpressionLanguage\ExpressionLanguage $expressionLanguage = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($expression, \Symfony\Component\Validator\Constraint $constraint): void
	{
	}
}
/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Bernhard Schussek <bschussek@symfony.com>
 */
class ExpressionValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function __construct(?\Symfony\Component\ExpressionLanguage\ExpressionLanguage $expressionLanguage = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @property int $maxSize
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class File extends \Symfony\Component\Validator\Constraint
{
	// Check the Image constraint for clashes if adding new constants here
	public const NOT_FOUND_ERROR = 'd2a3fb6e-7ddc-4210-8fbf-2ab345ce1998';
	public const NOT_READABLE_ERROR = 'c20c92a4-5bfa-4202-9477-28e800e0f6ff';
	public const EMPTY_ERROR = '5d743385-9775-4aa5-8ff5-495fb1e60137';
	public const TOO_LARGE_ERROR = 'df8637af-d466-48c6-a59d-e7126250a654';
	public const INVALID_MIME_TYPE_ERROR = '744f00bc-4389-4c74-92de-9a43cde55534';
	protected static $errorNames = [self::NOT_FOUND_ERROR => 'NOT_FOUND_ERROR', self::NOT_READABLE_ERROR => 'NOT_READABLE_ERROR', self::EMPTY_ERROR => 'EMPTY_ERROR', self::TOO_LARGE_ERROR => 'TOO_LARGE_ERROR', self::INVALID_MIME_TYPE_ERROR => 'INVALID_MIME_TYPE_ERROR'];
	public $binaryFormat;
	public $mimeTypes = [];
	public $notFoundMessage = 'The file could not be found.';
	public $notReadableMessage = 'The file is not readable.';
	public $maxSizeMessage = 'The file is too large ({{ size }} {{ suffix }}). Allowed maximum size is {{ limit }} {{ suffix }}.';
	public $mimeTypesMessage = 'The mime type of the file is invalid ({{ type }}). Allowed mime types are {{ types }}.';
	public $disallowEmptyMessage = 'An empty file is not allowed.';
	public $uploadIniSizeErrorMessage = 'The file is too large. Allowed maximum size is {{ limit }} {{ suffix }}.';
	public $uploadFormSizeErrorMessage = 'The file is too large.';
	public $uploadPartialErrorMessage = 'The file was only partially uploaded.';
	public $uploadNoFileErrorMessage = 'No file was uploaded.';
	public $uploadNoTmpDirErrorMessage = 'No temporary folder was configured in php.ini.';
	public $uploadCantWriteErrorMessage = 'Cannot write temporary file to disk.';
	public $uploadExtensionErrorMessage = 'A PHP extension caused the upload to fail.';
	public $uploadErrorMessage = 'The file could not be uploaded.';
	protected $maxSize;
	/**
	 * {@inheritdoc}
	 *
	 * @param int|string|null      $maxSize
	 * @param string[]|string|null $mimeTypes
	 */
	public function __construct(?array $options = null, $maxSize = null, ?bool $binaryFormat = null, $mimeTypes = null, ?string $notFoundMessage = null, ?string $notReadableMessage = null, ?string $maxSizeMessage = null, ?string $mimeTypesMessage = null, ?string $disallowEmptyMessage = null, ?string $uploadIniSizeErrorMessage = null, ?string $uploadFormSizeErrorMessage = null, ?string $uploadPartialErrorMessage = null, ?string $uploadNoFileErrorMessage = null, ?string $uploadNoTmpDirErrorMessage = null, ?string $uploadCantWriteErrorMessage = null, ?string $uploadExtensionErrorMessage = null, ?string $uploadErrorMessage = null, ?array $groups = null, $payload = null)
	{
	}
	public function __set(string $option, $value)
	{
	}
	public function __get(string $option)
	{
	}
	public function __isset(string $option)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class FileValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public const KB_BYTES = 1000;
	public const MB_BYTES = 1000000;
	public const KIB_BYTES = 1024;
	public const MIB_BYTES = 1048576;
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class GreaterThan extends \Symfony\Component\Validator\Constraints\AbstractComparison
{
	public const TOO_LOW_ERROR = '778b7ae0-84d3-481a-9dec-35fdb64b1d78';
	protected static $errorNames = [self::TOO_LOW_ERROR => 'TOO_LOW_ERROR'];
	public $message = 'This value should be greater than {{ compared_value }}.';
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class GreaterThanOrEqual extends \Symfony\Component\Validator\Constraints\AbstractComparison
{
	public const TOO_LOW_ERROR = 'ea4e51d1-3342-48bd-87f1-9e672cd90cad';
	protected static $errorNames = [self::TOO_LOW_ERROR => 'TOO_LOW_ERROR'];
	public $message = 'This value should be greater than or equal to {{ compared_value }}.';
}
/**
 * Validates values are greater than or equal to the previous (>=).
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class GreaterThanOrEqualValidator extends \Symfony\Component\Validator\Constraints\AbstractComparisonValidator
{
	/**
	 * {@inheritdoc}
	 */
	protected function compareValues($value1, $value2)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * Validates values are greater than the previous (>).
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class GreaterThanValidator extends \Symfony\Component\Validator\Constraints\AbstractComparisonValidator
{
	/**
	 * {@inheritdoc}
	 */
	protected function compareValues($value1, $value2)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * A sequence of validation groups.
 *
 * When validating a group sequence, each group will only be validated if all
 * of the previous groups in the sequence succeeded. For example:
 *
 *     $validator->validate($address, null, new GroupSequence(['Basic', 'Strict']));
 *
 * In the first step, all constraints that belong to the group "Basic" will be
 * validated. If none of the constraints fail, the validator will then validate
 * the constraints in group "Strict". This is useful, for example, if "Strict"
 * contains expensive checks that require a lot of CPU or slow, external
 * services. You usually don't want to run expensive checks if any of the cheap
 * checks fail.
 *
 * When adding metadata to a class, you can override the "Default" group of
 * that class with a group sequence:
 *     /**
 *      * @GroupSequence({"Address", "Strict"})
 *      *\/
 *     class Address
 *     {
 *         // ...
 *     }
 *
 * Whenever you validate that object in the "Default" group, the group sequence
 * will be validated:
 *
 *     $validator->validate($address);
 *
 * If you want to execute the constraints of the "Default" group for a class
 * with an overridden default group, pass the class name as group name instead:
 *
 *     $validator->validate($address, null, "Address")
 *
 * @Annotation
 * @Target({"CLASS", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class GroupSequence
{
	/**
	 * The groups in the sequence.
	 *
	 * @var array<int, string|string[]|GroupSequence>
	 */
	public $groups;
	/**
	 * The group in which cascaded objects are validated when validating
	 * this sequence.
	 *
	 * By default, cascaded objects are validated in each of the groups of
	 * the sequence.
	 *
	 * If a class has a group sequence attached, that sequence replaces the
	 * "Default" group. When validating that class in the "Default" group, the
	 * group sequence is used instead, but still the "Default" group should be
	 * cascaded to other objects.
	 *
	 * @var string|GroupSequence
	 */
	public $cascadedGroup;
	/**
	 * Creates a new group sequence.
	 *
	 * @param array<string|string[]|GroupSequence> $groups The groups in the sequence
	 */
	public function __construct(array $groups)
	{
	}
}
/**
 * Annotation to define a group sequence provider.
 *
 * @Annotation
 * @Target({"CLASS", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class GroupSequenceProvider
{
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Dmitrii Poddubnyi <dpoddubny@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Hostname extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_HOSTNAME_ERROR = '7057ffdb-0af4-4f7e-bd5e-e9acfa6d7a2d';
	protected static $errorNames = [self::INVALID_HOSTNAME_ERROR => 'INVALID_HOSTNAME_ERROR'];
	public $message = 'This value is not a valid hostname.';
	public $requireTld = true;
	public function __construct(?array $options = null, ?string $message = null, ?bool $requireTld = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Dmitrii Poddubnyi <dpoddubny@gmail.com>
 */
class HostnameValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Manuel Reinhard <manu@sprain.ch>
 * @author Michael Schummel
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Iban extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_COUNTRY_CODE_ERROR = 'de78ee2c-bd50-44e2-aec8-3d8228aeadb9';
	public const INVALID_CHARACTERS_ERROR = '8d3d85e4-784f-4719-a5bc-d9e40d45a3a5';
	public const CHECKSUM_FAILED_ERROR = 'b9401321-f9bf-4dcb-83c1-f31094440795';
	public const INVALID_FORMAT_ERROR = 'c8d318f1-2ecc-41ba-b983-df70d225cf5a';
	public const NOT_SUPPORTED_COUNTRY_CODE_ERROR = 'e2c259f3-4b46-48e6-b72e-891658158ec8';
	protected static $errorNames = [self::INVALID_COUNTRY_CODE_ERROR => 'INVALID_COUNTRY_CODE_ERROR', self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR', self::CHECKSUM_FAILED_ERROR => 'CHECKSUM_FAILED_ERROR', self::INVALID_FORMAT_ERROR => 'INVALID_FORMAT_ERROR', self::NOT_SUPPORTED_COUNTRY_CODE_ERROR => 'NOT_SUPPORTED_COUNTRY_CODE_ERROR'];
	public $message = 'This is not a valid International Bank Account Number (IBAN).';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Manuel Reinhard <manu@sprain.ch>
 * @author Michael Schummel
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class IbanValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class IdenticalTo extends \Symfony\Component\Validator\Constraints\AbstractComparison
{
	public const NOT_IDENTICAL_ERROR = '2a8cc50f-58a2-4536-875e-060a2ce69ed5';
	protected static $errorNames = [self::NOT_IDENTICAL_ERROR => 'NOT_IDENTICAL_ERROR'];
	public $message = 'This value should be identical to {{ compared_value_type }} {{ compared_value }}.';
}
/**
 * Validates values are identical (===).
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class IdenticalToValidator extends \Symfony\Component\Validator\Constraints\AbstractComparisonValidator
{
	/**
	 * {@inheritdoc}
	 */
	protected function compareValues($value1, $value2)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Benjamin Dulau <benjamin.dulau@gmail.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Image extends \Symfony\Component\Validator\Constraints\File
{
	public const SIZE_NOT_DETECTED_ERROR = '6d55c3f4-e58e-4fe3-91ee-74b492199956';
	public const TOO_WIDE_ERROR = '7f87163d-878f-47f5-99ba-a8eb723a1ab2';
	public const TOO_NARROW_ERROR = '9afbd561-4f90-4a27-be62-1780fc43604a';
	public const TOO_HIGH_ERROR = '7efae81c-4877-47ba-aa65-d01ccb0d4645';
	public const TOO_LOW_ERROR = 'aef0cb6a-c07f-4894-bc08-1781420d7b4c';
	public const TOO_FEW_PIXEL_ERROR = '1b06b97d-ae48-474e-978f-038a74854c43';
	public const TOO_MANY_PIXEL_ERROR = 'ee0804e8-44db-4eac-9775-be91aaf72ce1';
	public const RATIO_TOO_BIG_ERROR = '70cafca6-168f-41c9-8c8c-4e47a52be643';
	public const RATIO_TOO_SMALL_ERROR = '59b8c6ef-bcf2-4ceb-afff-4642ed92f12e';
	public const SQUARE_NOT_ALLOWED_ERROR = '5d41425b-facb-47f7-a55a-de9fbe45cb46';
	public const LANDSCAPE_NOT_ALLOWED_ERROR = '6f895685-7cf2-4d65-b3da-9029c5581d88';
	public const PORTRAIT_NOT_ALLOWED_ERROR = '65608156-77da-4c79-a88c-02ef6d18c782';
	public const CORRUPTED_IMAGE_ERROR = '5d4163f3-648f-4e39-87fd-cc5ea7aad2d1';
	// Include the mapping from the base class
	protected static $errorNames = [self::NOT_FOUND_ERROR => 'NOT_FOUND_ERROR', self::NOT_READABLE_ERROR => 'NOT_READABLE_ERROR', self::EMPTY_ERROR => 'EMPTY_ERROR', self::TOO_LARGE_ERROR => 'TOO_LARGE_ERROR', self::INVALID_MIME_TYPE_ERROR => 'INVALID_MIME_TYPE_ERROR', self::SIZE_NOT_DETECTED_ERROR => 'SIZE_NOT_DETECTED_ERROR', self::TOO_WIDE_ERROR => 'TOO_WIDE_ERROR', self::TOO_NARROW_ERROR => 'TOO_NARROW_ERROR', self::TOO_HIGH_ERROR => 'TOO_HIGH_ERROR', self::TOO_LOW_ERROR => 'TOO_LOW_ERROR', self::TOO_FEW_PIXEL_ERROR => 'TOO_FEW_PIXEL_ERROR', self::TOO_MANY_PIXEL_ERROR => 'TOO_MANY_PIXEL_ERROR', self::RATIO_TOO_BIG_ERROR => 'RATIO_TOO_BIG_ERROR', self::RATIO_TOO_SMALL_ERROR => 'RATIO_TOO_SMALL_ERROR', self::SQUARE_NOT_ALLOWED_ERROR => 'SQUARE_NOT_ALLOWED_ERROR', self::LANDSCAPE_NOT_ALLOWED_ERROR => 'LANDSCAPE_NOT_ALLOWED_ERROR', self::PORTRAIT_NOT_ALLOWED_ERROR => 'PORTRAIT_NOT_ALLOWED_ERROR', self::CORRUPTED_IMAGE_ERROR => 'CORRUPTED_IMAGE_ERROR'];
	public $mimeTypes = 'image/*';
	public $minWidth;
	public $maxWidth;
	public $maxHeight;
	public $minHeight;
	public $maxRatio;
	public $minRatio;
	public $minPixels;
	public $maxPixels;
	public $allowSquare = true;
	public $allowLandscape = true;
	public $allowPortrait = true;
	public $detectCorrupted = false;
	// The constant for a wrong MIME type is taken from the parent class.
	public $mimeTypesMessage = 'This file is not a valid image.';
	public $sizeNotDetectedMessage = 'The size of the image could not be detected.';
	public $maxWidthMessage = 'The image width is too big ({{ width }}px). Allowed maximum width is {{ max_width }}px.';
	public $minWidthMessage = 'The image width is too small ({{ width }}px). Minimum width expected is {{ min_width }}px.';
	public $maxHeightMessage = 'The image height is too big ({{ height }}px). Allowed maximum height is {{ max_height }}px.';
	public $minHeightMessage = 'The image height is too small ({{ height }}px). Minimum height expected is {{ min_height }}px.';
	public $minPixelsMessage = 'The image has too few pixels ({{ pixels }} pixels). Minimum amount expected is {{ min_pixels }} pixels.';
	public $maxPixelsMessage = 'The image has too many pixels ({{ pixels }} pixels). Maximum amount expected is {{ max_pixels }} pixels.';
	public $maxRatioMessage = 'The image ratio is too big ({{ ratio }}). Allowed maximum ratio is {{ max_ratio }}.';
	public $minRatioMessage = 'The image ratio is too small ({{ ratio }}). Minimum ratio expected is {{ min_ratio }}.';
	public $allowSquareMessage = 'The image is square ({{ width }}x{{ height }}px). Square images are not allowed.';
	public $allowLandscapeMessage = 'The image is landscape oriented ({{ width }}x{{ height }}px). Landscape oriented images are not allowed.';
	public $allowPortraitMessage = 'The image is portrait oriented ({{ width }}x{{ height }}px). Portrait oriented images are not allowed.';
	public $corruptedMessage = 'The image file is corrupted.';
	/**
	 * {@inheritdoc}
	 *
	 * @param int|float $maxRatio
	 * @param int|float $minRatio
	 * @param int|float $minPixels
	 * @param int|float $maxPixels
	 */
	public function __construct(?array $options = null, $maxSize = null, ?bool $binaryFormat = null, ?array $mimeTypes = null, ?int $minWidth = null, ?int $maxWidth = null, ?int $maxHeight = null, ?int $minHeight = null, $maxRatio = null, $minRatio = null, $minPixels = null, $maxPixels = null, ?bool $allowSquare = null, ?bool $allowLandscape = null, ?bool $allowPortrait = null, ?bool $detectCorrupted = null, ?string $notFoundMessage = null, ?string $notReadableMessage = null, ?string $maxSizeMessage = null, ?string $mimeTypesMessage = null, ?string $disallowEmptyMessage = null, ?string $uploadIniSizeErrorMessage = null, ?string $uploadFormSizeErrorMessage = null, ?string $uploadPartialErrorMessage = null, ?string $uploadNoFileErrorMessage = null, ?string $uploadNoTmpDirErrorMessage = null, ?string $uploadCantWriteErrorMessage = null, ?string $uploadExtensionErrorMessage = null, ?string $uploadErrorMessage = null, ?string $sizeNotDetectedMessage = null, ?string $maxWidthMessage = null, ?string $minWidthMessage = null, ?string $maxHeightMessage = null, ?string $minHeightMessage = null, ?string $minPixelsMessage = null, ?string $maxPixelsMessage = null, ?string $maxRatioMessage = null, ?string $minRatioMessage = null, ?string $allowSquareMessage = null, ?string $allowLandscapeMessage = null, ?string $allowPortraitMessage = null, ?string $corruptedMessage = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates whether a value is a valid image file and is valid
 * against minWidth, maxWidth, minHeight and maxHeight constraints.
 *
 * @author Benjamin Dulau <benjamin.dulau@gmail.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ImageValidator extends \Symfony\Component\Validator\Constraints\FileValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * Validates that a value is a valid IP address.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Joseph Bielawski <stloyd@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Ip extends \Symfony\Component\Validator\Constraint
{
	public const V4 = '4';
	public const V6 = '6';
	public const ALL = 'all';
	// adds FILTER_FLAG_NO_PRIV_RANGE flag (skip private ranges)
	public const V4_NO_PRIV = '4_no_priv';
	public const V6_NO_PRIV = '6_no_priv';
	public const ALL_NO_PRIV = 'all_no_priv';
	// adds FILTER_FLAG_NO_RES_RANGE flag (skip reserved ranges)
	public const V4_NO_RES = '4_no_res';
	public const V6_NO_RES = '6_no_res';
	public const ALL_NO_RES = 'all_no_res';
	// adds FILTER_FLAG_NO_PRIV_RANGE and FILTER_FLAG_NO_RES_RANGE flags (skip both)
	public const V4_ONLY_PUBLIC = '4_public';
	public const V6_ONLY_PUBLIC = '6_public';
	public const ALL_ONLY_PUBLIC = 'all_public';
	public const INVALID_IP_ERROR = 'b1b427ae-9f6f-41b0-aa9b-84511fbb3c5b';
	protected static $versions = [self::V4, self::V6, self::ALL, self::V4_NO_PRIV, self::V6_NO_PRIV, self::ALL_NO_PRIV, self::V4_NO_RES, self::V6_NO_RES, self::ALL_NO_RES, self::V4_ONLY_PUBLIC, self::V6_ONLY_PUBLIC, self::ALL_ONLY_PUBLIC];
	protected static $errorNames = [self::INVALID_IP_ERROR => 'INVALID_IP_ERROR'];
	public $version = self::V4;
	public $message = 'This is not a valid IP address.';
	public $normalizer;
	/**
	 * {@inheritdoc}
	 */
	public function __construct(?array $options = null, ?string $version = null, ?string $message = null, ?callable $normalizer = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates whether a value is a valid IP address.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Joseph Bielawski <stloyd@gmail.com>
 */
class IpValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author The Whole Life To Learn <thewholelifetolearn@gmail.com>
 * @author Manuel Reinhard <manu@sprain.ch>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Isbn extends \Symfony\Component\Validator\Constraint
{
	public const ISBN_10 = 'isbn10';
	public const ISBN_13 = 'isbn13';
	public const TOO_SHORT_ERROR = '949acbb0-8ef5-43ed-a0e9-032dfd08ae45';
	public const TOO_LONG_ERROR = '3171387d-f80a-47b3-bd6e-60598545316a';
	public const INVALID_CHARACTERS_ERROR = '23d21cea-da99-453d-98b1-a7d916fbb339';
	public const CHECKSUM_FAILED_ERROR = '2881c032-660f-46b6-8153-d352d9706640';
	public const TYPE_NOT_RECOGNIZED_ERROR = 'fa54a457-f042-441f-89c4-066ee5bdd3e1';
	protected static $errorNames = [self::TOO_SHORT_ERROR => 'TOO_SHORT_ERROR', self::TOO_LONG_ERROR => 'TOO_LONG_ERROR', self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR', self::CHECKSUM_FAILED_ERROR => 'CHECKSUM_FAILED_ERROR', self::TYPE_NOT_RECOGNIZED_ERROR => 'TYPE_NOT_RECOGNIZED_ERROR'];
	public $isbn10Message = 'This value is not a valid ISBN-10.';
	public $isbn13Message = 'This value is not a valid ISBN-13.';
	public $bothIsbnMessage = 'This value is neither a valid ISBN-10 nor a valid ISBN-13.';
	public $type;
	public $message;
	/**
	 * {@inheritdoc}
	 *
	 * @param string|array|null $type The ISBN standard to validate or a set of options
	 */
	public function __construct($type = null, ?string $message = null, ?string $isbn10Message = null, ?string $isbn13Message = null, ?string $bothIsbnMessage = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getDefaultOption()
	{
	}
}
/**
 * Validates whether the value is a valid ISBN-10 or ISBN-13.
 *
 * @author The Whole Life To Learn <thewholelifetolearn@gmail.com>
 * @author Manuel Reinhard <manu@sprain.ch>
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see https://en.wikipedia.org/wiki/Isbn
 */
class IsbnValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
	protected function validateIsbn10(string $isbn)
	{
	}
	protected function validateIsbn13(string $isbn)
	{
	}
	protected function getMessage(\Symfony\Component\Validator\Constraints\Isbn $constraint, ?string $type = null)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class IsFalse extends \Symfony\Component\Validator\Constraint
{
	public const NOT_FALSE_ERROR = 'd53a91b0-def3-426a-83d7-269da7ab4200';
	protected static $errorNames = [self::NOT_FALSE_ERROR => 'NOT_FALSE_ERROR'];
	public $message = 'This value should be false.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class IsFalseValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Laurent Masforné <l.masforne@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Isin extends \Symfony\Component\Validator\Constraint
{
	public const VALIDATION_LENGTH = 12;
	public const VALIDATION_PATTERN = '/[A-Z]{2}[A-Z0-9]{9}[0-9]{1}/';
	public const INVALID_LENGTH_ERROR = '88738dfc-9ed5-ba1e-aebe-402a2a9bf58e';
	public const INVALID_PATTERN_ERROR = '3d08ce0-ded9-a93d-9216-17ac21265b65e';
	public const INVALID_CHECKSUM_ERROR = '32089b-0ee1-93ba-399e-aa232e62f2d29d';
	protected static $errorNames = [self::INVALID_LENGTH_ERROR => 'INVALID_LENGTH_ERROR', self::INVALID_PATTERN_ERROR => 'INVALID_PATTERN_ERROR', self::INVALID_CHECKSUM_ERROR => 'INVALID_CHECKSUM_ERROR'];
	public $message = 'This value is not a valid International Securities Identification Number (ISIN).';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Laurent Masforné <l.masforne@gmail.com>
 *
 * @see https://en.wikipedia.org/wiki/International_Securities_Identification_Number
 */
class IsinValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class IsNull extends \Symfony\Component\Validator\Constraint
{
	public const NOT_NULL_ERROR = '60d2f30b-8cfa-4372-b155-9656634de120';
	protected static $errorNames = [self::NOT_NULL_ERROR => 'NOT_NULL_ERROR'];
	public $message = 'This value should be null.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class IsNullValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Antonio J. García Lagar <aj@garcialagar.es>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Issn extends \Symfony\Component\Validator\Constraint
{
	public const TOO_SHORT_ERROR = '6a20dd3d-f463-4460-8e7b-18a1b98abbfb';
	public const TOO_LONG_ERROR = '37cef893-5871-464e-8b12-7fb79324833c';
	public const MISSING_HYPHEN_ERROR = '2983286f-8134-4693-957a-1ec4ef887b15';
	public const INVALID_CHARACTERS_ERROR = 'a663d266-37c2-4ece-a914-ae891940c588';
	public const INVALID_CASE_ERROR = '7b6dd393-7523-4a6c-b84d-72b91bba5e1a';
	public const CHECKSUM_FAILED_ERROR = 'b0f92dbc-667c-48de-b526-ad9586d43e85';
	protected static $errorNames = [self::TOO_SHORT_ERROR => 'TOO_SHORT_ERROR', self::TOO_LONG_ERROR => 'TOO_LONG_ERROR', self::MISSING_HYPHEN_ERROR => 'MISSING_HYPHEN_ERROR', self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR', self::INVALID_CASE_ERROR => 'INVALID_CASE_ERROR', self::CHECKSUM_FAILED_ERROR => 'CHECKSUM_FAILED_ERROR'];
	public $message = 'This value is not a valid ISSN.';
	public $caseSensitive = false;
	public $requireHyphen = false;
	public function __construct(?array $options = null, ?string $message = null, ?bool $caseSensitive = null, ?bool $requireHyphen = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates whether the value is a valid ISSN.
 *
 * @author Antonio J. García Lagar <aj@garcialagar.es>
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see https://en.wikipedia.org/wiki/Issn
 */
class IssnValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class IsTrue extends \Symfony\Component\Validator\Constraint
{
	public const NOT_TRUE_ERROR = '2beabf1c-54c0-4882-a928-05249b26e23b';
	protected static $errorNames = [self::NOT_TRUE_ERROR => 'NOT_TRUE_ERROR'];
	public $message = 'This value should be true.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class IsTrueValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Imad ZAIRIG <imadzairig@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Json extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_JSON_ERROR = '0789c8ad-2d2b-49a4-8356-e2ce63998504';
	protected static $errorNames = [self::INVALID_JSON_ERROR => 'INVALID_JSON_ERROR'];
	public $message = 'This value should be valid JSON.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Imad ZAIRIG <imadzairig@gmail.com>
 */
class JsonValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Language extends \Symfony\Component\Validator\Constraint
{
	public const NO_SUCH_LANGUAGE_ERROR = 'ee65fec4-9a20-4202-9f39-ca558cd7bdf7';
	protected static $errorNames = [self::NO_SUCH_LANGUAGE_ERROR => 'NO_SUCH_LANGUAGE_ERROR'];
	public $message = 'This value is not a valid language.';
	public $alpha3 = false;
	public function __construct(?array $options = null, ?string $message = null, ?bool $alpha3 = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates whether a value is a valid language code.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class LanguageValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Length extends \Symfony\Component\Validator\Constraint
{
	public const TOO_SHORT_ERROR = '9ff3fdc4-b214-49db-8718-39c315e33d45';
	public const TOO_LONG_ERROR = 'd94b19cc-114f-4f44-9cc4-4138e80a87b9';
	public const NOT_EQUAL_LENGTH_ERROR = '4b6f5c76-22b4-409d-af16-fbe823ba9332';
	public const INVALID_CHARACTERS_ERROR = '35e6a710-aa2e-4719-b58e-24b35749b767';
	protected static $errorNames = [self::TOO_SHORT_ERROR => 'TOO_SHORT_ERROR', self::TOO_LONG_ERROR => 'TOO_LONG_ERROR', self::NOT_EQUAL_LENGTH_ERROR => 'NOT_EQUAL_LENGTH_ERROR', self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR'];
	public $maxMessage = 'This value is too long. It should have {{ limit }} character or less.|This value is too long. It should have {{ limit }} characters or less.';
	public $minMessage = 'This value is too short. It should have {{ limit }} character or more.|This value is too short. It should have {{ limit }} characters or more.';
	public $exactMessage = 'This value should have exactly {{ limit }} character.|This value should have exactly {{ limit }} characters.';
	public $charsetMessage = 'This value does not match the expected {{ charset }} charset.';
	public $max;
	public $min;
	public $charset = 'UTF-8';
	public $normalizer;
	public $allowEmptyString = false;
	/**
	 * {@inheritdoc}
	 *
	 * @param int|array|null $exactly The expected exact length or a set of options
	 */
	public function __construct($exactly = null, ?int $min = null, ?int $max = null, ?string $charset = null, ?callable $normalizer = null, ?string $exactMessage = null, ?string $minMessage = null, ?string $maxMessage = null, ?string $charsetMessage = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class LengthValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class LessThan extends \Symfony\Component\Validator\Constraints\AbstractComparison
{
	public const TOO_HIGH_ERROR = '079d7420-2d13-460c-8756-de810eeb37d2';
	protected static $errorNames = [self::TOO_HIGH_ERROR => 'TOO_HIGH_ERROR'];
	public $message = 'This value should be less than {{ compared_value }}.';
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class LessThanOrEqual extends \Symfony\Component\Validator\Constraints\AbstractComparison
{
	public const TOO_HIGH_ERROR = '30fbb013-d015-4232-8b3b-8f3be97a7e14';
	protected static $errorNames = [self::TOO_HIGH_ERROR => 'TOO_HIGH_ERROR'];
	public $message = 'This value should be less than or equal to {{ compared_value }}.';
}
/**
 * Validates values are less than or equal to the previous (<=).
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class LessThanOrEqualValidator extends \Symfony\Component\Validator\Constraints\AbstractComparisonValidator
{
	/**
	 * {@inheritdoc}
	 */
	protected function compareValues($value1, $value2)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * Validates values are less than the previous (<).
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class LessThanValidator extends \Symfony\Component\Validator\Constraints\AbstractComparisonValidator
{
	/**
	 * {@inheritdoc}
	 */
	protected function compareValues($value1, $value2)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Locale extends \Symfony\Component\Validator\Constraint
{
	public const NO_SUCH_LOCALE_ERROR = 'a0af4293-1f1a-4a1c-a328-979cba6182a2';
	protected static $errorNames = [self::NO_SUCH_LOCALE_ERROR => 'NO_SUCH_LOCALE_ERROR'];
	public $message = 'This value is not a valid locale.';
	public $canonicalize = true;
	public function __construct(?array $options = null, ?string $message = null, ?bool $canonicalize = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates whether a value is a valid locale code.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class LocaleValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * Metadata for the LuhnValidator.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Tim Nagel <t.nagel@infinite.net.au>
 * @author Greg Knapp http://gregk.me/2011/php-implementation-of-bank-card-luhn-algorithm/
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Luhn extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_CHARACTERS_ERROR = 'dfad6d23-1b74-4374-929b-5cbb56fc0d9e';
	public const CHECKSUM_FAILED_ERROR = '4d760774-3f50-4cd5-a6d5-b10a3299d8d3';
	protected static $errorNames = [self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR', self::CHECKSUM_FAILED_ERROR => 'CHECKSUM_FAILED_ERROR'];
	public $message = 'Invalid card number.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates a PAN using the LUHN Algorithm.
 *
 * For a list of example card numbers that are used to test this
 * class, please see the LuhnValidatorTest class.
 *
 * @see    http://en.wikipedia.org/wiki/Luhn_algorithm
 *
 * @author Tim Nagel <t.nagel@infinite.net.au>
 * @author Greg Knapp http://gregk.me/2011/php-implementation-of-bank-card-luhn-algorithm/
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class LuhnValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * Validates a credit card number with the Luhn algorithm.
	 *
	 * @param mixed $value
	 *
	 * @throws UnexpectedTypeException when the given credit card number is no string
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @internal
 *
 * @author Jan Schädlich <jan.schaedlich@sensiolabs.de>
 * @author Alexander M. Turek <me@derrabus.de>
 */
trait ZeroComparisonConstraintTrait
{
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
	public function validatedBy(): string
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Jan Schädlich <jan.schaedlich@sensiolabs.de>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Negative extends \Symfony\Component\Validator\Constraints\LessThan
{
	use \Symfony\Component\Validator\Constraints\ZeroComparisonConstraintTrait;
	public $message = 'This value should be negative.';
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Jan Schädlich <jan.schaedlich@sensiolabs.de>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NegativeOrZero extends \Symfony\Component\Validator\Constraints\LessThanOrEqual
{
	use \Symfony\Component\Validator\Constraints\ZeroComparisonConstraintTrait;
	public $message = 'This value should be either negative or zero.';
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotBlank extends \Symfony\Component\Validator\Constraint
{
	public const IS_BLANK_ERROR = 'c1051bb4-d103-4f74-8988-acbcafc7fdc3';
	protected static $errorNames = [self::IS_BLANK_ERROR => 'IS_BLANK_ERROR'];
	public $message = 'This value should not be blank.';
	public $allowNull = false;
	public $normalizer;
	public function __construct(?array $options = null, ?string $message = null, ?bool $allowNull = null, ?callable $normalizer = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class NotBlankValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * Checks if a password has been leaked in a data breach.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotCompromisedPassword extends \Symfony\Component\Validator\Constraint
{
	public const COMPROMISED_PASSWORD_ERROR = 'd9bcdbfe-a9d6-4bfa-a8ff-da5fd93e0f6d';
	protected static $errorNames = [self::COMPROMISED_PASSWORD_ERROR => 'COMPROMISED_PASSWORD_ERROR'];
	public $message = 'This password has been leaked in a data breach, it must not be used. Please use another password.';
	public $threshold = 1;
	public $skipOnError = false;
	public function __construct(?array $options = null, ?string $message = null, ?int $threshold = null, ?bool $skipOnError = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Checks if a password has been leaked in a data breach using haveibeenpwned.com's API.
 * Use a k-anonymity model to protect the password being searched for.
 *
 * @see https://haveibeenpwned.com/API/v2#SearchingPwnedPasswordsByRange
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class NotCompromisedPasswordValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function __construct(?\Symfony\Contracts\HttpClient\HttpClientInterface $httpClient = null, string $charset = 'UTF-8', bool $enabled = true, ?string $endpoint = null)
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * @throws ExceptionInterface
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotEqualTo extends \Symfony\Component\Validator\Constraints\AbstractComparison
{
	public const IS_EQUAL_ERROR = 'aa2e33da-25c8-4d76-8c6c-812f02ea89dd';
	protected static $errorNames = [self::IS_EQUAL_ERROR => 'IS_EQUAL_ERROR'];
	public $message = 'This value should not be equal to {{ compared_value }}.';
}
/**
 * Validates values are all unequal (!=).
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class NotEqualToValidator extends \Symfony\Component\Validator\Constraints\AbstractComparisonValidator
{
	/**
	 * {@inheritdoc}
	 */
	protected function compareValues($value1, $value2)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotIdenticalTo extends \Symfony\Component\Validator\Constraints\AbstractComparison
{
	public const IS_IDENTICAL_ERROR = '4aaac518-0dda-4129-a6d9-e216b9b454a0';
	protected static $errorNames = [self::IS_IDENTICAL_ERROR => 'IS_IDENTICAL_ERROR'];
	public $message = 'This value should not be identical to {{ compared_value_type }} {{ compared_value }}.';
}
/**
 * Validates values aren't identical (!==).
 *
 * @author Daniel Holmes <daniel@danielholmes.org>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class NotIdenticalToValidator extends \Symfony\Component\Validator\Constraints\AbstractComparisonValidator
{
	/**
	 * {@inheritdoc}
	 */
	protected function compareValues($value1, $value2)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function getErrorCode()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotNull extends \Symfony\Component\Validator\Constraint
{
	public const IS_NULL_ERROR = 'ad32d13f-c3d4-423b-909a-857b961eb720';
	protected static $errorNames = [self::IS_NULL_ERROR => 'IS_NULL_ERROR'];
	public $message = 'This value should not be null.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class NotNullValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @author Jan Schädlich <jan.schaedlich@sensiolabs.de>
 *
 * @deprecated since Symfony 5.2
 */
trait NumberConstraintTrait
{
	private function configureNumberConstraintOptions($options): array
	{
	}
}
/**
 * @Annotation
 * @Target({"ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class Optional extends \Symfony\Component\Validator\Constraints\Existence
{
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Jan Schädlich <jan.schaedlich@sensiolabs.de>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Positive extends \Symfony\Component\Validator\Constraints\GreaterThan
{
	use \Symfony\Component\Validator\Constraints\ZeroComparisonConstraintTrait;
	public $message = 'This value should be positive.';
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Jan Schädlich <jan.schaedlich@sensiolabs.de>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class PositiveOrZero extends \Symfony\Component\Validator\Constraints\GreaterThanOrEqual
{
	use \Symfony\Component\Validator\Constraints\ZeroComparisonConstraintTrait;
	public $message = 'This value should be either positive or zero.';
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Range extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_CHARACTERS_ERROR = 'ad9a9798-7a99-4df7-8ce9-46e416a1e60b';
	public const NOT_IN_RANGE_ERROR = '04b91c99-a946-4221-afc5-e65ebac401eb';
	public const TOO_HIGH_ERROR = '2d28afcb-e32e-45fb-a815-01c431a86a69';
	public const TOO_LOW_ERROR = '76454e69-502c-46c5-9643-f447d837c4d5';
	protected static $errorNames = [self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR', self::NOT_IN_RANGE_ERROR => 'NOT_IN_RANGE_ERROR', self::TOO_HIGH_ERROR => 'TOO_HIGH_ERROR', self::TOO_LOW_ERROR => 'TOO_LOW_ERROR'];
	public $notInRangeMessage = 'This value should be between {{ min }} and {{ max }}.';
	public $minMessage = 'This value should be {{ limit }} or more.';
	public $maxMessage = 'This value should be {{ limit }} or less.';
	public $invalidMessage = 'This value should be a valid number.';
	public $invalidDateTimeMessage = 'This value should be a valid datetime.';
	public $min;
	public $minPropertyPath;
	public $max;
	public $maxPropertyPath;
	/**
	 * @internal
	 */
	public $deprecatedMinMessageSet = false;
	/**
	 * @internal
	 */
	public $deprecatedMaxMessageSet = false;
	/**
	 * {@inheritdoc}
	 *
	 * @param string|PropertyPathInterface|null $minPropertyPath
	 * @param string|PropertyPathInterface|null $maxPropertyPath
	 */
	public function __construct(?array $options = null, ?string $notInRangeMessage = null, ?string $minMessage = null, ?string $maxMessage = null, ?string $invalidMessage = null, ?string $invalidDateTimeMessage = null, $min = null, $minPropertyPath = null, $max = null, $maxPropertyPath = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RangeValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function __construct(?\Symfony\Component\PropertyAccess\PropertyAccessorInterface $propertyAccessor = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Regex extends \Symfony\Component\Validator\Constraint
{
	public const REGEX_FAILED_ERROR = 'de1e3db3-5ed4-4941-aae4-59f3667cc3a3';
	protected static $errorNames = [self::REGEX_FAILED_ERROR => 'REGEX_FAILED_ERROR'];
	public $message = 'This value is not valid.';
	public $pattern;
	public $htmlPattern;
	public $match = true;
	public $normalizer;
	/**
	 * {@inheritdoc}
	 *
	 * @param string|array $pattern The pattern to evaluate or an array of options
	 */
	public function __construct($pattern, ?string $message = null, ?string $htmlPattern = null, ?bool $match = null, ?callable $normalizer = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getDefaultOption()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getRequiredOptions()
	{
	}
	/**
	 * Converts the htmlPattern to a suitable format for HTML5 pattern.
	 * Example: /^[a-z]+$/ would be converted to [a-z]+
	 * However, if options are specified, it cannot be converted.
	 *
	 * @see http://dev.w3.org/html5/spec/single-page.html#the-pattern-attribute
	 *
	 * @return string|null
	 */
	public function getHtmlPattern()
	{
	}
}
/**
 * Validates whether a value match or not given regexp pattern.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Joseph Bielawski <stloyd@gmail.com>
 */
class RegexValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class Required extends \Symfony\Component\Validator\Constraints\Existence
{
}
/**
 * Use this constraint to sequentially validate nested constraints.
 * Validation for the nested constraints collection will stop at first violation.
 *
 * @Annotation
 * @Target({"CLASS", "PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Sequentially extends \Symfony\Component\Validator\Constraints\Composite
{
	public $constraints = [];
	public function __construct($constraints = null, ?array $groups = null, $payload = null)
	{
	}
	public function getDefaultOption()
	{
	}
	public function getRequiredOptions()
	{
	}
	protected function getCompositeOption()
	{
	}
	public function getTargets()
	{
	}
}
/**
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
class SequentiallyValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Time extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_FORMAT_ERROR = '9d27b2bb-f755-4fbf-b725-39b1edbdebdf';
	public const INVALID_TIME_ERROR = '8532f9e1-84b2-4d67-8989-0818bc38533b';
	protected static $errorNames = [self::INVALID_FORMAT_ERROR => 'INVALID_FORMAT_ERROR', self::INVALID_TIME_ERROR => 'INVALID_TIME_ERROR'];
	public $message = 'This value is not a valid time.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class TimeValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public const PATTERN = '/^(\d{2}):(\d{2}):(\d{2})$/D';
	/**
	 * Checks whether a time is valid.
	 *
	 * @internal
	 */
	public static function checkTime(int $hour, int $minute, float $second): bool
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Javier Spagnoletti <phansys@gmail.com>
 * @author Hugo Hamon <hugohamon@neuf.fr>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Timezone extends \Symfony\Component\Validator\Constraint
{
	public const TIMEZONE_IDENTIFIER_ERROR = '5ce113e6-5e64-4ea2-90fe-d2233956db13';
	public const TIMEZONE_IDENTIFIER_IN_ZONE_ERROR = 'b57767b1-36c0-40ac-a3d7-629420c775b8';
	public const TIMEZONE_IDENTIFIER_IN_COUNTRY_ERROR = 'c4a22222-dc92-4fc0-abb0-d95b268c7d0b';
	public const TIMEZONE_IDENTIFIER_INTL_ERROR = '45863c26-88dc-41ba-bf53-c73bd1f7e90d';
	public $zone = \DateTimeZone::ALL;
	public $countryCode;
	public $intlCompatible = false;
	public $message = 'This value is not a valid timezone.';
	protected static $errorNames = [self::TIMEZONE_IDENTIFIER_ERROR => 'TIMEZONE_IDENTIFIER_ERROR', self::TIMEZONE_IDENTIFIER_IN_ZONE_ERROR => 'TIMEZONE_IDENTIFIER_IN_ZONE_ERROR', self::TIMEZONE_IDENTIFIER_IN_COUNTRY_ERROR => 'TIMEZONE_IDENTIFIER_IN_COUNTRY_ERROR', self::TIMEZONE_IDENTIFIER_INTL_ERROR => 'TIMEZONE_IDENTIFIER_INTL_ERROR'];
	/**
	 * {@inheritdoc}
	 *
	 * @param int|array|null $zone A combination of {@see \DateTimeZone} class constants or a set of options
	 */
	public function __construct($zone = null, ?string $message = null, ?string $countryCode = null, ?bool $intlCompatible = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getDefaultOption()
	{
	}
}
/**
 * Validates whether a value is a valid timezone identifier.
 *
 * @author Javier Spagnoletti <phansys@gmail.com>
 * @author Hugo Hamon <hugohamon@neuf.fr>
 */
class TimezoneValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Traverse extends \Symfony\Component\Validator\Constraint
{
	public $traverse = true;
	/**
	 * @param bool|array|null $traverse
	 */
	public function __construct($traverse = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getDefaultOption()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getTargets()
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Type extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_TYPE_ERROR = 'ba785a8c-82cb-4283-967c-3cf342181b40';
	protected static $errorNames = [self::INVALID_TYPE_ERROR => 'INVALID_TYPE_ERROR'];
	public $message = 'This value should be of type {{ type }}.';
	public $type;
	/**
	 * {@inheritdoc}
	 *
	 * @param string|array $type One ore multiple types to validate against or a set of options
	 */
	public function __construct($type, ?string $message = null, ?array $groups = null, $payload = null, array $options = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getDefaultOption()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getRequiredOptions()
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class TypeValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 *
 * @author Laurent Clouet <laurent35240@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Ulid extends \Symfony\Component\Validator\Constraint
{
	public const TOO_SHORT_ERROR = '7b44804e-37d5-4df4-9bdd-b738d4a45bb4';
	public const TOO_LONG_ERROR = '9608249f-6da1-4d53-889e-9864b58c4d37';
	public const INVALID_CHARACTERS_ERROR = 'e4155739-5135-4258-9c81-ae7b44b5311e';
	public const TOO_LARGE_ERROR = 'df8cfb9a-ce6d-4a69-ae5a-eea7ab6f278b';
	protected static $errorNames = [self::TOO_SHORT_ERROR => 'TOO_SHORT_ERROR', self::TOO_LONG_ERROR => 'TOO_LONG_ERROR', self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR', self::TOO_LARGE_ERROR => 'TOO_LARGE_ERROR'];
	public $message = 'This is not a valid ULID.';
	public function __construct(?array $options = null, ?string $message = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates whether the value is a valid ULID (Universally Unique Lexicographically Sortable Identifier).
 * Cf https://github.com/ulid/spec for ULID specifications.
 *
 * @author Laurent Clouet <laurent35240@gmail.com>
 */
class UlidValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Yevgeniy Zholkevskiy <zhenya.zholkevskiy@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Unique extends \Symfony\Component\Validator\Constraint
{
	public const IS_NOT_UNIQUE = '7911c98d-b845-4da0-94b7-a8dac36bc55a';
	protected static $errorNames = [self::IS_NOT_UNIQUE => 'IS_NOT_UNIQUE'];
	public $message = 'This collection should contain only unique elements.';
	public $normalizer;
	public function __construct(?array $options = null, ?string $message = null, ?callable $normalizer = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Yevgeniy Zholkevskiy <zhenya.zholkevskiy@gmail.com>
 */
class UniqueValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Url extends \Symfony\Component\Validator\Constraint
{
	public const INVALID_URL_ERROR = '57c2f299-1154-4870-89bb-ef3b1f5ad229';
	protected static $errorNames = [self::INVALID_URL_ERROR => 'INVALID_URL_ERROR'];
	public $message = 'This value is not a valid URL.';
	public $protocols = ['http', 'https'];
	public $relativeProtocol = false;
	public $normalizer;
	public function __construct(?array $options = null, ?string $message = null, ?array $protocols = null, ?bool $relativeProtocol = null, ?callable $normalizer = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class UrlValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public const PATTERN = '~^
            (%s)://                                 # protocol
            (((?:[\_\.\pL\pN-]|%%[0-9A-Fa-f]{2})+:)?((?:[\_\.\pL\pN-]|%%[0-9A-Fa-f]{2})+)@)?  # basic auth
            (
                (?:
                    (?:xn--[a-z0-9-]++\.)*+xn--[a-z0-9-]++            # a domain name using punycode
                        |
                    (?:[\pL\pN\pS\pM\-\_]++\.)+[\pL\pN\pM]++          # a multi-level domain name
                        |
                    [a-z0-9\-\_]++                                    # a single-level domain name
                )\.?
                    |                                                 # or
                \d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}                    # an IP address
                    |                                                 # or
                \[
                    (?:(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){6})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:::(?:(?:(?:[0-9a-f]{1,4})):){5})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:[0-9a-f]{1,4})))?::(?:(?:(?:[0-9a-f]{1,4})):){4})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,1}(?:(?:[0-9a-f]{1,4})))?::(?:(?:(?:[0-9a-f]{1,4})):){3})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,2}(?:(?:[0-9a-f]{1,4})))?::(?:(?:(?:[0-9a-f]{1,4})):){2})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,3}(?:(?:[0-9a-f]{1,4})))?::(?:(?:[0-9a-f]{1,4})):)(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,4}(?:(?:[0-9a-f]{1,4})))?::)(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,5}(?:(?:[0-9a-f]{1,4})))?::)(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,6}(?:(?:[0-9a-f]{1,4})))?::))))
                \]  # an IPv6 address
            )
            (:[0-9]+)?                              # a port (optional)
            (?:/ (?:[\pL\pN\pS\pM\-._\~!$&\'()*+,;=:@]|%%[0-9A-Fa-f]{2})* )*    # a path
            (?:\? (?:[\pL\pN\-._\~!$&\'\[\]()*+,;=:@/?]|%%[0-9A-Fa-f]{2})* )?   # a query (optional)
            (?:\# (?:[\pL\pN\-._\~!$&\'()*+,;=:@/?]|%%[0-9A-Fa-f]{2})* )?       # a fragment (optional)
        $~ixuD';
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 *
 * @author Colin O'Dell <colinodell@gmail.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Uuid extends \Symfony\Component\Validator\Constraint
{
	public const TOO_SHORT_ERROR = 'aa314679-dac9-4f54-bf97-b2049df8f2a3';
	public const TOO_LONG_ERROR = '494897dd-36f8-4d31-8923-71a8d5f3000d';
	public const INVALID_CHARACTERS_ERROR = '51120b12-a2bc-41bf-aa53-cd73daf330d0';
	public const INVALID_HYPHEN_PLACEMENT_ERROR = '98469c83-0309-4f5d-bf95-a496dcaa869c';
	public const INVALID_VERSION_ERROR = '21ba13b4-b185-4882-ac6f-d147355987eb';
	public const INVALID_VARIANT_ERROR = '164ef693-2b9d-46de-ad7f-836201f0c2db';
	protected static $errorNames = [self::TOO_SHORT_ERROR => 'TOO_SHORT_ERROR', self::TOO_LONG_ERROR => 'TOO_LONG_ERROR', self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR', self::INVALID_HYPHEN_PLACEMENT_ERROR => 'INVALID_HYPHEN_PLACEMENT_ERROR', self::INVALID_VERSION_ERROR => 'INVALID_VERSION_ERROR', self::INVALID_VARIANT_ERROR => 'INVALID_VARIANT_ERROR'];
	// Possible versions defined by RFC 9562/4122
	public const V1_MAC = 1;
	public const V2_DCE = 2;
	public const V3_MD5 = 3;
	public const V4_RANDOM = 4;
	public const V5_SHA1 = 5;
	public const V6_SORTABLE = 6;
	public const ALL_VERSIONS = [self::V1_MAC, self::V2_DCE, self::V3_MD5, self::V4_RANDOM, self::V5_SHA1, self::V6_SORTABLE];
	/**
	 * Message to display when validation fails.
	 *
	 * @var string
	 */
	public $message = 'This is not a valid UUID.';
	/**
	 * Strict mode only allows UUIDs that meet the formal definition and formatting per RFC 9562/4122.
	 *
	 * Set this to `false` to allow legacy formats with different dash positioning or wrapping characters
	 *
	 * @var bool
	 */
	public $strict = true;
	/**
	 * Array of allowed versions (see version constants above).
	 *
	 * All UUID versions are allowed by default
	 *
	 * @var int[]
	 */
	public $versions = self::ALL_VERSIONS;
	public $normalizer;
	/**
	 * {@inheritdoc}
	 *
	 * @param int[]|null $versions
	 */
	public function __construct(?array $options = null, ?string $message = null, ?array $versions = null, ?bool $strict = null, ?callable $normalizer = null, ?array $groups = null, $payload = null)
	{
	}
}
/**
 * Validates whether the value is a valid UUID (also known as GUID).
 *
 * Strict validation will allow a UUID as specified per RFC 9562/4122.
 * Loose validation will allow any type of UUID.
 *
 * @author Colin O'Dell <colinodell@gmail.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9562
 * @see https://en.wikipedia.org/wiki/Universally_unique_identifier
 */
class UuidValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	// The strict pattern matches UUIDs like this:
	// xxxxxxxx-xxxx-Mxxx-Nxxx-xxxxxxxxxxxx
	// Roughly speaking:
	// x = any hexadecimal character
	// M = any allowed version {1..6}
	// N = any allowed variant {8, 9, a, b}
	public const STRICT_LENGTH = 36;
	public const STRICT_FIRST_HYPHEN_POSITION = 8;
	public const STRICT_LAST_HYPHEN_POSITION = 23;
	public const STRICT_VERSION_POSITION = 14;
	public const STRICT_VARIANT_POSITION = 19;
	// The loose pattern validates similar yet non-compliant UUIDs.
	// Hyphens are completely optional. If present, they should only appear
	// between every fourth character:
	// xxxx-xxxx-xxxx-xxxx-xxxx-xxxx-xxxx-xxxx
	// xxxxxxxxxxxx-xxxx-xxxx-xxxx-xxxx-xxxx
	// xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
	// The value can also be wrapped with characters like []{}:
	// {xxxx-xxxx-xxxx-xxxx-xxxx-xxxx-xxxx-xxxx}
	// Neither the version nor the variant is validated by this pattern.
	public const LOOSE_MAX_LENGTH = 39;
	public const LOOSE_FIRST_HYPHEN_POSITION = 4;
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Valid extends \Symfony\Component\Validator\Constraint
{
	public $traverse = true;
	public function __construct(?array $options = null, ?array $groups = null, $payload = null, ?bool $traverse = null)
	{
	}
	public function __get(string $option)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function addImplicitGroupName(string $group)
	{
	}
}
/**
 * @author Christian Flothmann <christian.flothmann@sensiolabs.de>
 */
class ValidValidator extends \Symfony\Component\Validator\ConstraintValidator
{
	public function validate($value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
}

namespace Symfony\Component\Validator;

/**
 * Specifies an object able to return the correct ConstraintValidatorInterface
 * instance given a Constraint object.
 */
interface ConstraintValidatorFactoryInterface
{
	/**
	 * Given a Constraint, this returns the ConstraintValidatorInterface
	 * object that should be used to verify its validity.
	 *
	 * @return ConstraintValidatorInterface
	 */
	public function getInstance(\Symfony\Component\Validator\Constraint $constraint);
}
/**
 * Default implementation of the ConstraintValidatorFactoryInterface.
 *
 * This enforces the convention that the validatedBy() method on any
 * Constraint will return the class name of the ConstraintValidator that
 * should validate the Constraint.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ConstraintValidatorFactory implements \Symfony\Component\Validator\ConstraintValidatorFactoryInterface
{
	protected $validators = [];
	public function __construct()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getInstance(\Symfony\Component\Validator\Constraint $constraint)
	{
	}
}
/**
 * A violation of a constraint that happened during validation.
 *
 * For each constraint that fails during validation one or more violations are
 * created. The violations store the violation message, the path to the failing
 * element in the validation graph and the root element that was originally
 * passed to the validator. For example, take the following graph:
 *
 *     (Person)---(firstName: string)
 *          \
 *       (address: Address)---(street: string)
 *
 * If the <tt>Person</tt> object is validated and validation fails for the
 * "firstName" property, the generated violation has the <tt>Person</tt>
 * instance as root and the property path "firstName". If validation fails
 * for the "street" property of the related <tt>Address</tt> instance, the root
 * element is still the person, but the property path is "address.street".
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ConstraintViolationInterface
{
	/**
	 * Returns the violation message.
	 *
	 * @return string|\Stringable
	 */
	public function getMessage();
	/**
	 * Returns the raw violation message.
	 *
	 * The raw violation message contains placeholders for the parameters
	 * returned by {@link getParameters}. Typically you'll pass the
	 * message template and parameters to a translation engine.
	 *
	 * @return string The raw violation message
	 */
	public function getMessageTemplate();
	/**
	 * Returns the parameters to be inserted into the raw violation message.
	 *
	 * @return array a possibly empty list of parameters indexed by the names
	 *               that appear in the message template
	 *
	 * @see getMessageTemplate()
	 */
	public function getParameters();
	/**
	 * Returns a number for pluralizing the violation message.
	 *
	 * For example, the message template could have different translation based
	 * on a parameter "choices":
	 *
	 * <ul>
	 * <li>Please select exactly one entry. (choices=1)</li>
	 * <li>Please select two entries. (choices=2)</li>
	 * </ul>
	 *
	 * This method returns the value of the parameter for choosing the right
	 * pluralization form (in this case "choices").
	 *
	 * @return int|null The number to use to pluralize of the message
	 */
	public function getPlural();
	/**
	 * Returns the root element of the validation.
	 *
	 * @return mixed The value that was passed originally to the validator when
	 *               the validation was started. Because the validator traverses
	 *               the object graph, the value at which the violation occurs
	 *               is not necessarily the value that was originally validated.
	 */
	public function getRoot();
	/**
	 * Returns the property path from the root element to the violation.
	 *
	 * @return string The property path indicates how the validator reached
	 *                the invalid value from the root element. If the root
	 *                element is a <tt>Person</tt> instance with a property
	 *                "address" that contains an <tt>Address</tt> instance
	 *                with an invalid property "street", the generated property
	 *                path is "address.street". Property access is denoted by
	 *                dots, while array access is denoted by square brackets,
	 *                for example "addresses[1].street".
	 */
	public function getPropertyPath();
	/**
	 * Returns the value that caused the violation.
	 *
	 * @return mixed the invalid value that caused the validated constraint to
	 *               fail
	 */
	public function getInvalidValue();
	/**
	 * Returns a machine-digestible error code for the violation.
	 *
	 * @return string|null
	 */
	public function getCode();
}
/**
 * Default implementation of {@ConstraintViolationInterface}.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ConstraintViolation implements \Symfony\Component\Validator\ConstraintViolationInterface
{
	/**
	 * Creates a new constraint violation.
	 *
	 * @param string|\Stringable $message         The violation message as a string or a stringable object
	 * @param string|null        $messageTemplate The raw violation message
	 * @param array              $parameters      The parameters to substitute in the
	 *                                            raw violation message
	 * @param mixed              $root            The value originally passed to the
	 *                                            validator
	 * @param string|null        $propertyPath    The property path from the root
	 *                                            value to the invalid value
	 * @param mixed              $invalidValue    The invalid value that caused this
	 *                                            violation
	 * @param int|null           $plural          The number for determining the plural
	 *                                            form when translating the message
	 * @param string|null        $code            The error code of the violation
	 * @param Constraint|null    $constraint      The constraint whose validation
	 *                                            caused the violation
	 * @param mixed              $cause           The cause of the violation
	 */
	public function __construct($message, ?string $messageTemplate, array $parameters, $root, ?string $propertyPath, $invalidValue, ?int $plural = null, ?string $code = null, ?\Symfony\Component\Validator\Constraint $constraint = null, $cause = null)
	{
	}
	/**
	 * Converts the violation into a string for debugging purposes.
	 *
	 * @return string
	 */
	public function __toString()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getMessageTemplate()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getParameters()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getPlural()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getMessage()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getRoot()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getPropertyPath()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getInvalidValue()
	{
	}
	/**
	 * Returns the constraint whose validation caused the violation.
	 *
	 * @return Constraint|null
	 */
	public function getConstraint()
	{
	}
	/**
	 * Returns the cause of the violation.
	 *
	 * @return mixed
	 */
	public function getCause()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getCode()
	{
	}
}
/**
 * A list of constraint violations.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @extends \ArrayAccess<int, ConstraintViolationInterface>
 * @extends \Traversable<int, ConstraintViolationInterface>
 */
interface ConstraintViolationListInterface extends \Traversable, \Countable, \ArrayAccess
{
	/**
	 * Adds a constraint violation to this list.
	 */
	public function add(\Symfony\Component\Validator\ConstraintViolationInterface $violation);
	/**
	 * Merges an existing violation list into this list.
	 */
	public function addAll(self $otherList);
	/**
	 * Returns the violation at a given offset.
	 *
	 * @param int $offset The offset of the violation
	 *
	 * @return ConstraintViolationInterface
	 *
	 * @throws \OutOfBoundsException if the offset does not exist
	 */
	public function get(int $offset);
	/**
	 * Returns whether the given offset exists.
	 *
	 * @param int $offset The violation offset
	 *
	 * @return bool
	 */
	public function has(int $offset);
	/**
	 * Sets a violation at a given offset.
	 *
	 * @param int $offset The violation offset
	 */
	public function set(int $offset, \Symfony\Component\Validator\ConstraintViolationInterface $violation);
	/**
	 * Removes a violation at a given offset.
	 *
	 * @param int $offset The offset to remove
	 */
	public function remove(int $offset);
}
/**
 * Default implementation of {@ConstraintViolationListInterface}.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @implements \IteratorAggregate<int, ConstraintViolationInterface>
 */
class ConstraintViolationList implements \IteratorAggregate, \Symfony\Component\Validator\ConstraintViolationListInterface
{
	/**
	 * Creates a new constraint violation list.
	 *
	 * @param iterable<mixed, ConstraintViolationInterface> $violations The constraint violations to add to the list
	 */
	public function __construct(iterable $violations = [])
	{
	}
	public static function createFromMessage(string $message): self
	{
	}
	/**
	 * Converts the violation into a string for debugging purposes.
	 *
	 * @return string
	 */
	public function __toString()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function add(\Symfony\Component\Validator\ConstraintViolationInterface $violation)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function addAll(\Symfony\Component\Validator\ConstraintViolationListInterface $otherList)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function get(int $offset)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function has(int $offset)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function set(int $offset, \Symfony\Component\Validator\ConstraintViolationInterface $violation)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function remove(int $offset)
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * @return \ArrayIterator<int, ConstraintViolationInterface>
	 */
	#[\ReturnTypeWillChange]
	public function getIterator()
	{
	}
	/**
	 * @return int
	 */
	#[\ReturnTypeWillChange]
	public function count()
	{
	}
	/**
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function offsetExists($offset)
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * @return ConstraintViolationInterface
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet($offset)
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet($offset, $violation)
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetUnset($offset)
	{
	}
	/**
	 * Creates iterator for errors with specific codes.
	 *
	 * @param string|string[] $codes The codes to find
	 *
	 * @return static
	 */
	public function findByCodes($codes)
	{
	}
}
/**
 * Uses a service container to create constraint validators.
 *
 * @author Kris Wallsmith <kris@symfony.com>
 */
class ContainerConstraintValidatorFactory implements \Symfony\Component\Validator\ConstraintValidatorFactoryInterface
{
	public function __construct(\Psr\Container\ContainerInterface $container)
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * @throws ValidatorException      When the validator class does not exist
	 * @throws UnexpectedTypeException When the validator is not an instance of ConstraintValidatorInterface
	 */
	public function getInstance(\Symfony\Component\Validator\Constraint $constraint)
	{
	}
}

namespace Symfony\Component\Validator\Context;

/**
 * The context of a validation run.
 *
 * The context collects all violations generated during the validation. By
 * default, validators execute all validations in a new context:
 *
 *     $violations = $validator->validate($object);
 *
 * When you make another call to the validator, while the validation is in
 * progress, the violations will be isolated from each other:
 *
 *     public function validate($value, Constraint $constraint)
 *     {
 *         $validator = $this->context->getValidator();
 *
 *         // The violations are not added to $this->context
 *         $violations = $validator->validate($value);
 *     }
 *
 * However, if you want to add the violations to the current context, use the
 * {@link ValidatorInterface::inContext()} method:
 *
 *     public function validate($value, Constraint $constraint)
 *     {
 *         $validator = $this->context->getValidator();
 *
 *         // The violations are added to $this->context
 *         $validator
 *             ->inContext($this->context)
 *             ->validate($value)
 *         ;
 *     }
 *
 * Additionally, the context provides information about the current state of
 * the validator, such as the currently validated class, the name of the
 * currently validated property and more. These values change over time, so you
 * cannot store a context and expect that the methods still return the same
 * results later on.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ExecutionContextInterface
{
	/**
	 * Adds a violation at the current node of the validation graph.
	 *
	 * @param string|\Stringable $message The error message as a string or a stringable object
	 * @param array              $params  The parameters substituted in the error message
	 */
	public function addViolation(string $message, array $params = []);
	/**
	 * Returns a builder for adding a violation with extended information.
	 *
	 * Call {@link ConstraintViolationBuilderInterface::addViolation()} to
	 * add the violation when you're done with the configuration:
	 *
	 *     $context->buildViolation('Please enter a number between %min% and %max%.')
	 *         ->setParameter('%min%', '3')
	 *         ->setParameter('%max%', '10')
	 *         ->setTranslationDomain('number_validation')
	 *         ->addViolation();
	 *
	 * @param string|\Stringable $message    The error message as a string or a stringable object
	 * @param array              $parameters The parameters substituted in the error message
	 *
	 * @return ConstraintViolationBuilderInterface
	 */
	public function buildViolation(string $message, array $parameters = []);
	/**
	 * Returns the validator.
	 *
	 * Useful if you want to validate additional constraints:
	 *
	 *     public function validate($value, Constraint $constraint)
	 *     {
	 *         $validator = $this->context->getValidator();
	 *
	 *         $violations = $validator->validate($value, new Length(['min' => 3]));
	 *
	 *         if (count($violations) > 0) {
	 *             // ...
	 *         }
	 *     }
	 *
	 * @return ValidatorInterface
	 */
	public function getValidator();
	/**
	 * Returns the currently validated object.
	 *
	 * If the validator is currently validating a class constraint, the
	 * object of that class is returned. If it is validating a property or
	 * getter constraint, the object that the property/getter belongs to is
	 * returned.
	 *
	 * In other cases, null is returned.
	 *
	 * @return object|null
	 */
	public function getObject();
	/**
	 * Warning: Should not be called by user code, to be used by the validator engine only.
	 *
	 * @param mixed       $value        The validated value
	 * @param object|null $object       The currently validated object
	 * @param string      $propertyPath The property path to the current value
	 */
	public function setNode($value, ?object $object, ?\Symfony\Component\Validator\Mapping\MetadataInterface $metadata, string $propertyPath);
	/**
	 * Warning: Should not be called by user code, to be used by the validator engine only.
	 *
	 * @param string|null $group The validated group
	 */
	public function setGroup(?string $group);
	/**
	 * Warning: Should not be called by user code, to be used by the validator engine only.
	 */
	public function setConstraint(\Symfony\Component\Validator\Constraint $constraint);
	/**
	 * Warning: Should not be called by user code, to be used by the validator engine only.
	 *
	 * @param string $cacheKey  The hash of the object
	 * @param string $groupHash The group's name or hash, if it is group
	 *                          sequence
	 */
	public function markGroupAsValidated(string $cacheKey, string $groupHash);
	/**
	 * Warning: Should not be called by user code, to be used by the validator engine only.
	 *
	 * @param string $cacheKey  The hash of the object
	 * @param string $groupHash The group's name or hash, if it is group
	 *                          sequence
	 *
	 * @return bool
	 */
	public function isGroupValidated(string $cacheKey, string $groupHash);
	/**
	 * Warning: Should not be called by user code, to be used by the validator engine only.
	 *
	 * @param string $cacheKey       The hash of the object
	 * @param string $constraintHash The hash of the constraint
	 */
	public function markConstraintAsValidated(string $cacheKey, string $constraintHash);
	/**
	 * Warning: Should not be called by user code, to be used by the validator engine only.
	 *
	 * @param string $cacheKey       The hash of the object
	 * @param string $constraintHash The hash of the constraint
	 *
	 * @return bool
	 */
	public function isConstraintValidated(string $cacheKey, string $constraintHash);
	/**
	 * Warning: Should not be called by user code, to be used by the validator engine only.
	 *
	 * @param string $cacheKey The hash of the object
	 *
	 * @see ObjectInitializerInterface
	 */
	public function markObjectAsInitialized(string $cacheKey);
	/**
	 * Warning: Should not be called by user code, to be used by the validator engine only.
	 *
	 * @param string $cacheKey The hash of the object
	 *
	 * @return bool
	 *
	 * @see ObjectInitializerInterface
	 */
	public function isObjectInitialized(string $cacheKey);
	/**
	 * Returns the violations generated by the validator so far.
	 *
	 * @return ConstraintViolationListInterface
	 */
	public function getViolations();
	/**
	 * Returns the value at which validation was started in the object graph.
	 *
	 * The validator, when given an object, traverses the properties and
	 * related objects and their properties. The root of the validation is the
	 * object from which the traversal started.
	 *
	 * The current value is returned by {@link getValue}.
	 *
	 * @return mixed
	 */
	public function getRoot();
	/**
	 * Returns the value that the validator is currently validating.
	 *
	 * If you want to retrieve the object that was originally passed to the
	 * validator, use {@link getRoot}.
	 *
	 * @return mixed
	 */
	public function getValue();
	/**
	 * Returns the metadata for the currently validated value.
	 *
	 * With the core implementation, this method returns a
	 * {@link Mapping\ClassMetadataInterface} instance if the current value is an object,
	 * a {@link Mapping\PropertyMetadata} instance if the current value is
	 * the value of a property and a {@link Mapping\GetterMetadata} instance if
	 * the validated value is the result of a getter method.
	 *
	 * If the validated value is neither of these, for example if the validator
	 * has been called with a plain value and constraint, this method returns
	 * null.
	 *
	 * @return MetadataInterface|null
	 */
	public function getMetadata();
	/**
	 * Returns the validation group that is currently being validated.
	 *
	 * @return string|null
	 */
	public function getGroup();
	/**
	 * Returns the class name of the current node.
	 *
	 * If the metadata of the current node does not implement
	 * {@link Mapping\ClassMetadataInterface} or if no metadata is available for the
	 * current node, this method returns null.
	 *
	 * @return string|null
	 */
	public function getClassName();
	/**
	 * Returns the property name of the current node.
	 *
	 * If the metadata of the current node does not implement
	 * {@link PropertyMetadataInterface} or if no metadata is available for the
	 * current node, this method returns null.
	 *
	 * @return string|null
	 */
	public function getPropertyName();
	/**
	 * Returns the property path to the value that the validator is currently
	 * validating.
	 *
	 * For example, take the following object graph:
	 *
	 * <pre>
	 * (Person)---($address: Address)---($street: string)
	 * </pre>
	 *
	 * When the <tt>Person</tt> instance is passed to the validator, the
	 * property path is initially empty. When the <tt>$address</tt> property
	 * of that person is validated, the property path is "address". When
	 * the <tt>$street</tt> property of the related <tt>Address</tt> instance
	 * is validated, the property path is "address.street".
	 *
	 * Properties of objects are prefixed with a dot in the property path.
	 * Indices of arrays or objects implementing the {@link \ArrayAccess}
	 * interface are enclosed in brackets. For example, if the property in
	 * the previous example is <tt>$addresses</tt> and contains an array
	 * of <tt>Address</tt> instance, the property path generated for the
	 * <tt>$street</tt> property of one of these addresses is for example
	 * "addresses[0].street".
	 *
	 * @param string $subPath Optional. The suffix appended to the current
	 *                        property path.
	 *
	 * @return string The current property path. The result may be an empty
	 *                string if the validator is currently validating the
	 *                root value of the validation graph.
	 */
	public function getPropertyPath(string $subPath = '');
}
/**
 * The context used and created by {@link ExecutionContextFactory}.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see ExecutionContextInterface
 *
 * @internal since version 2.5. Code against ExecutionContextInterface instead.
 */
class ExecutionContext implements \Symfony\Component\Validator\Context\ExecutionContextInterface
{
	/**
	 * @param mixed $root The root value of the validated object graph
	 *
	 * @internal Called by {@link ExecutionContextFactory}. Should not be used in user code.
	 */
	public function __construct(\Symfony\Component\Validator\Validator\ValidatorInterface $validator, $root, \Symfony\Contracts\Translation\TranslatorInterface $translator, ?string $translationDomain = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setNode($value, ?object $object, ?\Symfony\Component\Validator\Mapping\MetadataInterface $metadata, string $propertyPath)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setGroup(?string $group)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setConstraint(\Symfony\Component\Validator\Constraint $constraint)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function addViolation(string $message, array $parameters = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function buildViolation(string $message, array $parameters = []): \Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getViolations(): \Symfony\Component\Validator\ConstraintViolationListInterface
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getValidator(): \Symfony\Component\Validator\Validator\ValidatorInterface
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getRoot()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getValue()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getObject()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getMetadata(): ?\Symfony\Component\Validator\Mapping\MetadataInterface
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getGroup(): ?string
	{
	}
	public function getConstraint(): ?\Symfony\Component\Validator\Constraint
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getClassName(): ?string
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getPropertyName(): ?string
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getPropertyPath(string $subPath = ''): string
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function markGroupAsValidated(string $cacheKey, string $groupHash)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function isGroupValidated(string $cacheKey, string $groupHash): bool
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function markConstraintAsValidated(string $cacheKey, string $constraintHash)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function isConstraintValidated(string $cacheKey, string $constraintHash): bool
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function markObjectAsInitialized(string $cacheKey)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function isObjectInitialized(string $cacheKey): bool
	{
	}
	/**
	 * @internal
	 */
	public function generateCacheKey(object $object): string
	{
	}
	public function __clone()
	{
	}
}
/**
 * Creates instances of {@link ExecutionContextInterface}.
 *
 * You can use a custom factory if you want to customize the execution context
 * that is passed through the validation run.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ExecutionContextFactoryInterface
{
	/**
	 * Creates a new execution context.
	 *
	 * @param mixed $root The root value of the validated
	 *                    object graph
	 *
	 * @return ExecutionContextInterface
	 */
	public function createContext(\Symfony\Component\Validator\Validator\ValidatorInterface $validator, $root);
}
/**
 * Creates new {@link ExecutionContext} instances.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @internal version 2.5. Code against ExecutionContextFactoryInterface instead.
 */
class ExecutionContextFactory implements \Symfony\Component\Validator\Context\ExecutionContextFactoryInterface
{
	public function __construct(\Symfony\Contracts\Translation\TranslatorInterface $translator, ?string $translationDomain = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function createContext(\Symfony\Component\Validator\Validator\ValidatorInterface $validator, $root)
	{
	}
}

namespace Symfony\Component\Validator\DataCollector;

/**
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 *
 * @final
 */
class ValidatorDataCollector extends \Symfony\Component\HttpKernel\DataCollector\DataCollector implements \Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface
{
	public function __construct(\Symfony\Component\Validator\Validator\TraceableValidator $validator)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function collect(\Symfony\Component\HttpFoundation\Request $request, \Symfony\Component\HttpFoundation\Response $response, ?\Throwable $exception = null)
	{
	}
	public function reset()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function lateCollect()
	{
	}
	public function getCalls(): \Symfony\Component\VarDumper\Cloner\Data
	{
	}
	public function getViolationsCount(): int
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getName(): string
	{
	}
	protected function getCasters(): array
	{
	}
}

namespace Symfony\Component\Validator\DependencyInjection;

/**
 * Injects the automapping configuration as last argument of loaders tagged with the "validator.auto_mapper" tag.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class AddAutoMappingConfigurationPass implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface
{
	public function __construct(string $validatorBuilderService = 'validator.builder', string $tag = 'validator.auto_mapper')
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function process(\Symfony\Component\DependencyInjection\ContainerBuilder $container)
	{
	}
}
/**
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 * @author Robin Chalas <robin.chalas@gmail.com>
 */
class AddConstraintValidatorsPass implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface
{
	public function __construct(string $validatorFactoryServiceId = 'validator.validator_factory', string $constraintValidatorTag = 'validator.constraint_validator')
	{
	}
	public function process(\Symfony\Component\DependencyInjection\ContainerBuilder $container)
	{
	}
}
/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Robin Chalas <robin.chalas@gmail.com>
 */
class AddValidatorInitializersPass implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface
{
	public function __construct(string $builderService = 'validator.builder', string $initializerTag = 'validator.initializer')
	{
	}
	public function process(\Symfony\Component\DependencyInjection\ContainerBuilder $container)
	{
	}
}

namespace Symfony\Component\Validator\Exception;

/**
 * Base ExceptionInterface for the Validator component.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ExceptionInterface extends \Throwable
{
}
/**
 * Base BadMethodCallException for the Validator component.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class BadMethodCallException extends \BadMethodCallException implements \Symfony\Component\Validator\Exception\ExceptionInterface
{
}
/**
 * Base RuntimeException for the Validator component.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RuntimeException extends \RuntimeException implements \Symfony\Component\Validator\Exception\ExceptionInterface
{
}
class ValidatorException extends \Symfony\Component\Validator\Exception\RuntimeException
{
}
class ConstraintDefinitionException extends \Symfony\Component\Validator\Exception\ValidatorException
{
}
class GroupDefinitionException extends \Symfony\Component\Validator\Exception\ValidatorException
{
}
/**
 * Base InvalidArgumentException for the Validator component.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class InvalidArgumentException extends \InvalidArgumentException implements \Symfony\Component\Validator\Exception\ExceptionInterface
{
}
class InvalidOptionsException extends \Symfony\Component\Validator\Exception\ValidatorException
{
	public function __construct(string $message, array $options)
	{
	}
	public function getOptions()
	{
	}
}
class LogicException extends \LogicException implements \Symfony\Component\Validator\Exception\ExceptionInterface
{
}
class MappingException extends \Symfony\Component\Validator\Exception\ValidatorException
{
}
class MissingOptionsException extends \Symfony\Component\Validator\Exception\ValidatorException
{
	public function __construct(string $message, array $options)
	{
	}
	public function getOptions()
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class NoSuchMetadataException extends \Symfony\Component\Validator\Exception\ValidatorException
{
}
/**
 * Base OutOfBoundsException for the Validator component.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class OutOfBoundsException extends \OutOfBoundsException implements \Symfony\Component\Validator\Exception\ExceptionInterface
{
}
class UnexpectedTypeException extends \Symfony\Component\Validator\Exception\ValidatorException
{
	public function __construct($value, string $expectedType)
	{
	}
}
/**
 * @author Christian Flothmann <christian.flothmann@sensiolabs.de>
 */
class UnexpectedValueException extends \Symfony\Component\Validator\Exception\UnexpectedTypeException
{
	public function __construct($value, string $expectedType)
	{
	}
	public function getExpectedType(): string
	{
	}
}
/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class UnsupportedMetadataException extends \Symfony\Component\Validator\Exception\InvalidArgumentException
{
}
/**
 * @author Jan Vernieuwe <jan.vernieuwe@phpro.be>
 */
class ValidationFailedException extends \Symfony\Component\Validator\Exception\RuntimeException
{
	public function __construct($value, \Symfony\Component\Validator\ConstraintViolationListInterface $violations)
	{
	}
	public function getValue()
	{
	}
	public function getViolations(): \Symfony\Component\Validator\ConstraintViolationListInterface
	{
	}
}

namespace Symfony\Component\Validator;

/**
 * Defines the interface for a group sequence provider.
 */
interface GroupSequenceProviderInterface
{
	/**
	 * Returns which validation groups should be used for a certain state
	 * of the object.
	 *
	 * @return string[]|string[][]|GroupSequence
	 */
	public function getGroupSequence();
}

namespace Symfony\Component\Validator\Mapping;

/**
 * Specifies how the auto-mapping feature should behave.
 *
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
final class AutoMappingStrategy
{
	/**
	 * Nothing explicitly set, rely on auto-mapping configured regex.
	 */
	public const NONE = 0;
	/**
	 * Explicitly enabled.
	 */
	public const ENABLED = 1;
	/**
	 * Explicitly disabled.
	 */
	public const DISABLED = 2;
}
/**
 * Specifies whether an object should be cascaded.
 *
 * Cascading is relevant for any node type but class nodes. If such a node
 * contains an object of value, and if cascading is enabled, then the node
 * traverser will try to find class metadata for that object and validate the
 * object against that metadata.
 *
 * If no metadata is found for a cascaded object, and if that object implements
 * {@link \Traversable}, the node traverser will iterate over the object and
 * cascade each object or collection contained within, unless iteration is
 * prohibited by the specified {@link TraversalStrategy}.
 *
 * Although the constants currently represent a boolean switch, they are
 * implemented as bit mask in order to allow future extensions.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see TraversalStrategy
 */
class CascadingStrategy
{
	/**
	 * Specifies that a node should not be cascaded.
	 */
	public const NONE = 1;
	/**
	 * Specifies that a node should be cascaded.
	 */
	public const CASCADE = 2;
}
/**
 * A container for validation metadata.
 *
 * Most importantly, the metadata stores the constraints against which an object
 * and its properties should be validated.
 *
 * Additionally, the metadata stores whether objects should be validated
 * against their class' metadata and whether traversable objects should be
 * traversed or not.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see CascadingStrategy
 * @see TraversalStrategy
 */
interface MetadataInterface
{
	/**
	 * Returns the strategy for cascading objects.
	 *
	 * @return int
	 *
	 * @see CascadingStrategy
	 */
	public function getCascadingStrategy();
	/**
	 * Returns the strategy for traversing traversable objects.
	 *
	 * @return int
	 *
	 * @see TraversalStrategy
	 */
	public function getTraversalStrategy();
	/**
	 * Returns all constraints of this element.
	 *
	 * @return Constraint[]
	 */
	public function getConstraints();
	/**
	 * Returns all constraints for a given validation group.
	 *
	 * @param string $group The validation group
	 *
	 * @return Constraint[]
	 */
	public function findConstraints(string $group);
}
/**
 * Stores all metadata needed for validating objects of specific class.
 *
 * Most importantly, the metadata stores the constraints against which an object
 * and its properties should be validated.
 *
 * Additionally, the metadata stores whether the "Default" group is overridden
 * by a group sequence for that class and whether instances of that class
 * should be traversed or not.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see MetadataInterface
 * @see GroupSequence
 * @see GroupSequenceProviderInterface
 * @see TraversalStrategy
 */
interface ClassMetadataInterface extends \Symfony\Component\Validator\Mapping\MetadataInterface
{
	/**
	 * Returns the names of all constrained properties.
	 *
	 * @return string[]
	 */
	public function getConstrainedProperties();
	/**
	 * Returns whether the "Default" group is overridden by a group sequence.
	 *
	 * If it is, you can access the group sequence with {@link getGroupSequence()}.
	 *
	 * @return bool
	 */
	public function hasGroupSequence();
	/**
	 * Returns the group sequence that overrides the "Default" group for this
	 * class.
	 *
	 * @return GroupSequence|null
	 */
	public function getGroupSequence();
	/**
	 * Returns whether the "Default" group is overridden by a dynamic group
	 * sequence obtained by the validated objects.
	 *
	 * If this method returns true, the class must implement
	 * {@link GroupSequenceProviderInterface}.
	 * This interface will be used to obtain the group sequence when an object
	 * of this class is validated.
	 *
	 * @return bool
	 */
	public function isGroupSequenceProvider();
	/**
	 * Check if there's any metadata attached to the given named property.
	 *
	 * @param string $property The property name
	 *
	 * @return bool
	 */
	public function hasPropertyMetadata(string $property);
	/**
	 * Returns all metadata instances for the given named property.
	 *
	 * If your implementation does not support properties, throw an exception
	 * in this method (for example a <tt>BadMethodCallException</tt>).
	 *
	 * @param string $property The property name
	 *
	 * @return PropertyMetadataInterface[]
	 */
	public function getPropertyMetadata(string $property);
	/**
	 * Returns the name of the backing PHP class.
	 *
	 * @return string
	 */
	public function getClassName();
}
/**
 * A generic container of {@link Constraint} objects.
 *
 * This class supports serialization and cloning.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class GenericMetadata implements \Symfony\Component\Validator\Mapping\MetadataInterface
{
	/**
	 * @var Constraint[]
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getConstraints()} and {@link findConstraints()} instead.
	 */
	public $constraints = [];
	/**
	 * @var array
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link findConstraints()} instead.
	 */
	public $constraintsByGroup = [];
	/**
	 * The strategy for cascading objects.
	 *
	 * By default, objects are not cascaded.
	 *
	 * @var int
	 *
	 * @see CascadingStrategy
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getCascadingStrategy()} instead.
	 */
	public $cascadingStrategy = \Symfony\Component\Validator\Mapping\CascadingStrategy::NONE;
	/**
	 * The strategy for traversing traversable objects.
	 *
	 * By default, traversable objects are not traversed.
	 *
	 * @var int
	 *
	 * @see TraversalStrategy
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getTraversalStrategy()} instead.
	 */
	public $traversalStrategy = \Symfony\Component\Validator\Mapping\TraversalStrategy::NONE;
	/**
	 * Is auto-mapping enabled?
	 *
	 * @var int
	 *
	 * @see AutoMappingStrategy
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getAutoMappingStrategy()} instead.
	 */
	public $autoMappingStrategy = \Symfony\Component\Validator\Mapping\AutoMappingStrategy::NONE;
	/**
	 * Returns the names of the properties that should be serialized.
	 *
	 * @return string[]
	 */
	public function __sleep()
	{
	}
	/**
	 * Clones this object.
	 */
	public function __clone()
	{
	}
	/**
	 * Adds a constraint.
	 *
	 * If the constraint {@link Valid} is added, the cascading strategy will be
	 * changed to {@link CascadingStrategy::CASCADE}. Depending on the
	 * $traverse property of that constraint, the traversal strategy
	 * will be set to one of the following:
	 *
	 *  - {@link TraversalStrategy::IMPLICIT} if $traverse is enabled
	 *  - {@link TraversalStrategy::NONE} if $traverse is disabled
	 *
	 * @return $this
	 *
	 * @throws ConstraintDefinitionException When trying to add the {@link Cascade}
	 *                                       or {@link Traverse} constraint
	 */
	public function addConstraint(\Symfony\Component\Validator\Constraint $constraint)
	{
	}
	/**
	 * Adds an list of constraints.
	 *
	 * @param Constraint[] $constraints The constraints to add
	 *
	 * @return $this
	 */
	public function addConstraints(array $constraints)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getConstraints()
	{
	}
	/**
	 * Returns whether this element has any constraints.
	 *
	 * @return bool
	 */
	public function hasConstraints()
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * Aware of the global group (* group).
	 */
	public function findConstraints(string $group)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getCascadingStrategy()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getTraversalStrategy()
	{
	}
	/**
	 * @see AutoMappingStrategy
	 */
	public function getAutoMappingStrategy(): int
	{
	}
}
/**
 * Default implementation of {@link ClassMetadataInterface}.
 *
 * This class supports serialization and cloning.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Fabien Potencier <fabien@symfony.com>
 */
class ClassMetadata extends \Symfony\Component\Validator\Mapping\GenericMetadata implements \Symfony\Component\Validator\Mapping\ClassMetadataInterface
{
	/**
	 * @var string
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getClassName()} instead.
	 */
	public $name;
	/**
	 * @var string
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getDefaultGroup()} instead.
	 */
	public $defaultGroup;
	/**
	 * @var MemberMetadata[][]
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getPropertyMetadata()} instead.
	 */
	public $members = [];
	/**
	 * @var PropertyMetadata[]
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getPropertyMetadata()} instead.
	 */
	public $properties = [];
	/**
	 * @var GetterMetadata[]
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getPropertyMetadata()} instead.
	 */
	public $getters = [];
	/**
	 * @var array
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getGroupSequence()} instead.
	 */
	public $groupSequence = [];
	/**
	 * @var bool
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link isGroupSequenceProvider()} instead.
	 */
	public $groupSequenceProvider = false;
	/**
	 * The strategy for traversing traversable objects.
	 *
	 * By default, only instances of {@link \Traversable} are traversed.
	 *
	 * @var int
	 *
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getTraversalStrategy()} instead.
	 */
	public $traversalStrategy = \Symfony\Component\Validator\Mapping\TraversalStrategy::IMPLICIT;
	public function __construct(string $class)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function __sleep()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getClassName()
	{
	}
	/**
	 * Returns the name of the default group for this class.
	 *
	 * For each class, the group "Default" is an alias for the group
	 * "<ClassName>", where <ClassName> is the non-namespaced name of the
	 * class. All constraints implicitly or explicitly assigned to group
	 * "Default" belong to both of these groups, unless the class defines
	 * a group sequence.
	 *
	 * If a class defines a group sequence, validating the class in "Default"
	 * will validate the group sequence. The constraints assigned to "Default"
	 * can still be validated by validating the class in "<ClassName>".
	 *
	 * @return string
	 */
	public function getDefaultGroup()
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * If the constraint {@link Cascade} is added, the cascading strategy will be
	 * changed to {@link CascadingStrategy::CASCADE}.
	 *
	 * If the constraint {@link Traverse} is added, the traversal strategy will be
	 * changed. Depending on the $traverse property of that constraint,
	 * the traversal strategy will be set to one of the following:
	 *
	 *  - {@link TraversalStrategy::IMPLICIT} by default
	 *  - {@link TraversalStrategy::NONE} if $traverse is disabled
	 *  - {@link TraversalStrategy::TRAVERSE} if $traverse is enabled
	 */
	public function addConstraint(\Symfony\Component\Validator\Constraint $constraint)
	{
	}
	/**
	 * Adds a constraint to the given property.
	 *
	 * @return $this
	 */
	public function addPropertyConstraint(string $property, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
	/**
	 * @param Constraint[] $constraints
	 *
	 * @return $this
	 */
	public function addPropertyConstraints(string $property, array $constraints)
	{
	}
	/**
	 * Adds a constraint to the getter of the given property.
	 *
	 * The name of the getter is assumed to be the name of the property with an
	 * uppercased first letter and the prefix "get", "is" or "has".
	 *
	 * @return $this
	 */
	public function addGetterConstraint(string $property, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
	/**
	 * Adds a constraint to the getter of the given property.
	 *
	 * @return $this
	 */
	public function addGetterMethodConstraint(string $property, string $method, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
	/**
	 * @param Constraint[] $constraints
	 *
	 * @return $this
	 */
	public function addGetterConstraints(string $property, array $constraints)
	{
	}
	/**
	 * @param Constraint[] $constraints
	 *
	 * @return $this
	 */
	public function addGetterMethodConstraints(string $property, string $method, array $constraints)
	{
	}
	/**
	 * Merges the constraints of the given metadata into this object.
	 */
	public function mergeConstraints(self $source)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function hasPropertyMetadata(string $property)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getPropertyMetadata(string $property)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getConstrainedProperties()
	{
	}
	/**
	 * Sets the default group sequence for this class.
	 *
	 * @param string[]|GroupSequence $groupSequence An array of group names
	 *
	 * @return $this
	 *
	 * @throws GroupDefinitionException
	 */
	public function setGroupSequence($groupSequence)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function hasGroupSequence()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getGroupSequence()
	{
	}
	/**
	 * Returns a ReflectionClass instance for this class.
	 *
	 * @return \ReflectionClass
	 */
	public function getReflectionClass()
	{
	}
	/**
	 * Sets whether a group sequence provider should be used.
	 *
	 * @throws GroupDefinitionException
	 */
	public function setGroupSequenceProvider(bool $active)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function isGroupSequenceProvider()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getCascadingStrategy()
	{
	}
}

namespace Symfony\Component\Validator\Mapping\Factory;

/**
 * Returns {@link \Symfony\Component\Validator\Mapping\MetadataInterface} instances for values.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface MetadataFactoryInterface
{
	/**
	 * Returns the metadata for the given value.
	 *
	 * @param mixed $value Some value
	 *
	 * @return MetadataInterface
	 *
	 * @throws NoSuchMetadataException If no metadata exists for the given value
	 */
	public function getMetadataFor($value);
	/**
	 * Returns whether the class is able to return metadata for the given value.
	 *
	 * @param mixed $value Some value
	 *
	 * @return bool
	 */
	public function hasMetadataFor($value);
}
/**
 * Metadata factory that does not store metadata.
 *
 * This implementation is useful if you want to validate values against
 * constraints only and you don't need to add constraints to classes and
 * properties.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class BlackHoleMetadataFactory implements \Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function getMetadataFor($value)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function hasMetadataFor($value)
	{
	}
}
/**
 * Creates new {@link ClassMetadataInterface} instances.
 *
 * Whenever {@link getMetadataFor()} is called for the first time with a given
 * class name or object of that class, a new metadata instance is created and
 * returned. On subsequent requests for the same class, the same metadata
 * instance will be returned.
 *
 * You can optionally pass a {@link LoaderInterface} instance to the constructor.
 * Whenever a new metadata instance is created, it is passed to the loader,
 * which can configure the metadata based on configuration loaded from the
 * filesystem or a database. If you want to use multiple loaders, wrap them in a
 * {@link LoaderChain}.
 *
 * You can also optionally pass a {@link CacheInterface} instance to the
 * constructor. This cache will be used for persisting the generated metadata
 * between multiple PHP requests.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class LazyLoadingMetadataFactory implements \Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface
{
	protected $loader;
	protected $cache;
	/**
	 * The loaded metadata, indexed by class name.
	 *
	 * @var ClassMetadata[]
	 */
	protected $loadedClasses = [];
	public function __construct(?\Symfony\Component\Validator\Mapping\Loader\LoaderInterface $loader = null, ?\Psr\Cache\CacheItemPoolInterface $cache = null)
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * If the method was called with the same class name (or an object of that
	 * class) before, the same metadata instance is returned.
	 *
	 * If the factory was configured with a cache, this method will first look
	 * for an existing metadata instance in the cache. If an existing instance
	 * is found, it will be returned without further ado.
	 *
	 * Otherwise, a new metadata instance is created. If the factory was
	 * configured with a loader, the metadata is passed to the
	 * {@link LoaderInterface::loadClassMetadata()} method for further
	 * configuration. At last, the new object is returned.
	 */
	public function getMetadataFor($value)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function hasMetadataFor($value)
	{
	}
}

namespace Symfony\Component\Validator\Mapping;

/**
 * Stores all metadata needed for validating the value of a class property.
 *
 * Most importantly, the metadata stores the constraints against which the
 * property's value should be validated.
 *
 * Additionally, the metadata stores whether objects stored in the property
 * should be validated against their class' metadata and whether traversable
 * objects should be traversed or not.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see MetadataInterface
 * @see CascadingStrategy
 * @see TraversalStrategy
 */
interface PropertyMetadataInterface extends \Symfony\Component\Validator\Mapping\MetadataInterface
{
	/**
	 * Returns the name of the property.
	 *
	 * @return string
	 */
	public function getPropertyName();
	/**
	 * Extracts the value of the property from the given container.
	 *
	 * @param mixed $containingValue The container to extract the property value from
	 *
	 * @return mixed
	 */
	public function getPropertyValue($containingValue);
}
/**
 * Stores all metadata needed for validating a class property.
 *
 * The method of accessing the property's value must be specified by subclasses
 * by implementing the {@link newReflectionMember()} method.
 *
 * This class supports serialization and cloning.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see PropertyMetadataInterface
 */
abstract class MemberMetadata extends \Symfony\Component\Validator\Mapping\GenericMetadata implements \Symfony\Component\Validator\Mapping\PropertyMetadataInterface
{
	/**
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getClassName()} instead.
	 */
	public $class;
	/**
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getName()} instead.
	 */
	public $name;
	/**
	 * @internal This property is public in order to reduce the size of the
	 *           class' serialized representation. Do not access it. Use
	 *           {@link getPropertyName()} instead.
	 */
	public $property;
	/**
	 * @param string $class    The name of the class this member is defined on
	 * @param string $name     The name of the member
	 * @param string $property The property the member belongs to
	 */
	public function __construct(string $class, string $name, string $property)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function addConstraint(\Symfony\Component\Validator\Constraint $constraint)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function __sleep()
	{
	}
	/**
	 * Returns the name of the member.
	 *
	 * @return string
	 */
	public function getName()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getClassName()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getPropertyName()
	{
	}
	/**
	 * Returns whether this member is public.
	 *
	 * @param object|string $objectOrClassName The object or the class name
	 *
	 * @return bool
	 */
	public function isPublic($objectOrClassName)
	{
	}
	/**
	 * Returns whether this member is protected.
	 *
	 * @param object|string $objectOrClassName The object or the class name
	 *
	 * @return bool
	 */
	public function isProtected($objectOrClassName)
	{
	}
	/**
	 * Returns whether this member is private.
	 *
	 * @param object|string $objectOrClassName The object or the class name
	 *
	 * @return bool
	 */
	public function isPrivate($objectOrClassName)
	{
	}
	/**
	 * Returns the reflection instance for accessing the member's value.
	 *
	 * @param object|string $objectOrClassName The object or the class name
	 *
	 * @return \ReflectionMethod|\ReflectionProperty
	 */
	public function getReflectionMember($objectOrClassName)
	{
	}
	/**
	 * Creates a new reflection instance for accessing the member's value.
	 *
	 * @param object|string $objectOrClassName The object or the class name
	 *
	 * @return \ReflectionMethod|\ReflectionProperty
	 */
	abstract protected function newReflectionMember($objectOrClassName);
}
/**
 * Stores all metadata needed for validating a class property via its getter
 * method.
 *
 * A property getter is any method that is equal to the property's name,
 * prefixed with "get", "is" or "has". That method will be used to access the
 * property's value.
 *
 * The getter will be invoked by reflection, so the access of private and
 * protected getters is supported.
 *
 * This class supports serialization and cloning.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see PropertyMetadataInterface
 */
class GetterMetadata extends \Symfony\Component\Validator\Mapping\MemberMetadata
{
	/**
	 * @param string      $class    The class the getter is defined on
	 * @param string      $property The property which the getter returns
	 * @param string|null $method   The method that is called to retrieve the value being validated (null for auto-detection)
	 *
	 * @throws ValidatorException
	 */
	public function __construct(string $class, string $property, ?string $method = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getPropertyValue($object)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function newReflectionMember($objectOrClassName)
	{
	}
}

namespace Symfony\Component\Validator\Mapping\Loader;

/**
 * Loads validation metadata into {@link ClassMetadata} instances.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface LoaderInterface
{
	/**
	 * Loads validation metadata into a {@link ClassMetadata} instance.
	 *
	 * @return bool
	 */
	public function loadClassMetadata(\Symfony\Component\Validator\Mapping\ClassMetadata $metadata);
}
/**
 * Base loader for validation metadata.
 *
 * This loader supports the loading of constraints from Symfony's default
 * namespace (see {@link DEFAULT_NAMESPACE}) using the short class names of
 * those constraints. Constraints can also be loaded using their fully
 * qualified class names. At last, namespace aliases can be defined to load
 * constraints with the syntax "alias:ShortName".
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
abstract class AbstractLoader implements \Symfony\Component\Validator\Mapping\Loader\LoaderInterface
{
	/**
	 * The namespace to load constraints from by default.
	 */
	public const DEFAULT_NAMESPACE = '\Symfony\Component\Validator\Constraints\\';
	protected $namespaces = [];
	/**
	 * Adds a namespace alias.
	 *
	 * The namespace alias can be used to reference constraints from specific
	 * namespaces in {@link newConstraint()}:
	 *
	 *     $this->addNamespaceAlias('mynamespace', '\\Acme\\Package\\Constraints\\');
	 *
	 *     $constraint = $this->newConstraint('mynamespace:NotNull');
	 */
	protected function addNamespaceAlias(string $alias, string $namespace)
	{
	}
	/**
	 * Creates a new constraint instance for the given constraint name.
	 *
	 * @param string $name    The constraint name. Either a constraint relative
	 *                        to the default constraint namespace, or a fully
	 *                        qualified class name. Alternatively, the constraint
	 *                        may be preceded by a namespace alias and a colon.
	 *                        The namespace alias must have been defined using
	 *                        {@link addNamespaceAlias()}.
	 * @param mixed  $options The constraint options
	 *
	 * @return Constraint
	 *
	 * @throws MappingException If the namespace prefix is undefined
	 */
	protected function newConstraint(string $name, $options = null)
	{
	}
}
/**
 * Loads validation metadata using a Doctrine annotation {@link Reader} or using PHP 8 attributes.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Alexander M. Turek <me@derrabus.de>
 */
class AnnotationLoader implements \Symfony\Component\Validator\Mapping\Loader\LoaderInterface
{
	protected $reader;
	public function __construct(?\Doctrine\Common\Annotations\Reader $reader = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function loadClassMetadata(\Symfony\Component\Validator\Mapping\ClassMetadata $metadata)
	{
	}
}
/**
 * Utility methods to create auto mapping loaders.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
trait AutoMappingTrait
{
	private function isAutoMappingEnabledForClass(\Symfony\Component\Validator\Mapping\ClassMetadata $metadata, ?string $classValidatorRegexp = null): bool
	{
	}
}
/**
 * Base loader for loading validation metadata from a file.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see YamlFileLoader
 * @see XmlFileLoader
 */
abstract class FileLoader extends \Symfony\Component\Validator\Mapping\Loader\AbstractLoader
{
	protected $file;
	/**
	 * Creates a new loader.
	 *
	 * @param string $file The mapping file to load
	 *
	 * @throws MappingException If the file does not exist or is not readable
	 */
	public function __construct(string $file)
	{
	}
}
/**
 * Loads validation metadata from multiple {@link LoaderInterface} instances.
 *
 * Pass the loaders when constructing the chain. Once
 * {@link loadClassMetadata()} is called, that method will be called on all
 * loaders in the chain.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class LoaderChain implements \Symfony\Component\Validator\Mapping\Loader\LoaderInterface
{
	protected $loaders;
	/**
	 * @param LoaderInterface[] $loaders The metadata loaders to use
	 *
	 * @throws MappingException If any of the loaders has an invalid type
	 */
	public function __construct(array $loaders)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function loadClassMetadata(\Symfony\Component\Validator\Mapping\ClassMetadata $metadata)
	{
	}
	/**
	 * @return LoaderInterface[]
	 */
	public function getLoaders()
	{
	}
}
/**
 * Base loader for loading validation metadata from a list of files.
 *
 * @author Bulat Shakirzyanov <mallluhuct@gmail.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see YamlFilesLoader
 * @see XmlFilesLoader
 */
abstract class FilesLoader extends \Symfony\Component\Validator\Mapping\Loader\LoaderChain
{
	/**
	 * Creates a new loader.
	 *
	 * @param array $paths An array of file paths
	 */
	public function __construct(array $paths)
	{
	}
	/**
	 * Returns an array of file loaders for the given file paths.
	 *
	 * @return LoaderInterface[]
	 */
	protected function getFileLoaders(array $paths)
	{
	}
	/**
	 * Creates a loader for the given file path.
	 *
	 * @return LoaderInterface
	 */
	abstract protected function getFileLoaderInstance(string $path);
}
/**
 * Guesses and loads the appropriate constraints using PropertyInfo.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class PropertyInfoLoader implements \Symfony\Component\Validator\Mapping\Loader\LoaderInterface
{
	use \Symfony\Component\Validator\Mapping\Loader\AutoMappingTrait;
	public function __construct(\Symfony\Component\PropertyInfo\PropertyListExtractorInterface $listExtractor, \Symfony\Component\PropertyInfo\PropertyTypeExtractorInterface $typeExtractor, \Symfony\Component\PropertyInfo\PropertyAccessExtractorInterface $accessExtractor, ?string $classValidatorRegexp = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function loadClassMetadata(\Symfony\Component\Validator\Mapping\ClassMetadata $metadata): bool
	{
	}
}
/**
 * Loads validation metadata by calling a static method on the loaded class.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class StaticMethodLoader implements \Symfony\Component\Validator\Mapping\Loader\LoaderInterface
{
	protected $methodName;
	/**
	 * Creates a new loader.
	 *
	 * @param string $methodName The name of the static method to call
	 */
	public function __construct(string $methodName = 'loadValidatorMetadata')
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function loadClassMetadata(\Symfony\Component\Validator\Mapping\ClassMetadata $metadata)
	{
	}
}
/**
 * Loads validation metadata from an XML file.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class XmlFileLoader extends \Symfony\Component\Validator\Mapping\Loader\FileLoader
{
	/**
	 * The XML nodes of the mapping file.
	 *
	 * @var \SimpleXMLElement[]|null
	 */
	protected $classes;
	/**
	 * {@inheritdoc}
	 */
	public function loadClassMetadata(\Symfony\Component\Validator\Mapping\ClassMetadata $metadata)
	{
	}
	/**
	 * Return the names of the classes mapped in this file.
	 *
	 * @return string[]
	 */
	public function getMappedClasses()
	{
	}
	/**
	 * Parses a collection of "constraint" XML nodes.
	 *
	 * @param \SimpleXMLElement $nodes The XML nodes
	 *
	 * @return Constraint[]
	 */
	protected function parseConstraints(\SimpleXMLElement $nodes)
	{
	}
	/**
	 * Parses a collection of "value" XML nodes.
	 *
	 * @param \SimpleXMLElement $nodes The XML nodes
	 *
	 * @return array
	 */
	protected function parseValues(\SimpleXMLElement $nodes)
	{
	}
	/**
	 * Parses a collection of "option" XML nodes.
	 *
	 * @param \SimpleXMLElement $nodes The XML nodes
	 *
	 * @return array
	 */
	protected function parseOptions(\SimpleXMLElement $nodes)
	{
	}
	/**
	 * Loads the XML class descriptions from the given file.
	 *
	 * @return \SimpleXMLElement
	 *
	 * @throws MappingException If the file could not be loaded
	 */
	protected function parseFile(string $path)
	{
	}
}
/**
 * Loads validation metadata from a list of XML files.
 *
 * @author Bulat Shakirzyanov <mallluhuct@gmail.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see FilesLoader
 */
class XmlFilesLoader extends \Symfony\Component\Validator\Mapping\Loader\FilesLoader
{
	/**
	 * {@inheritdoc}
	 */
	public function getFileLoaderInstance(string $file)
	{
	}
}
/**
 * Loads validation metadata from a YAML file.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class YamlFileLoader extends \Symfony\Component\Validator\Mapping\Loader\FileLoader
{
	/**
	 * An array of YAML class descriptions.
	 *
	 * @var array
	 */
	protected $classes = null;
	/**
	 * {@inheritdoc}
	 */
	public function loadClassMetadata(\Symfony\Component\Validator\Mapping\ClassMetadata $metadata)
	{
	}
	/**
	 * Return the names of the classes mapped in this file.
	 *
	 * @return string[]
	 */
	public function getMappedClasses()
	{
	}
	/**
	 * Parses a collection of YAML nodes.
	 *
	 * @param array $nodes The YAML nodes
	 *
	 * @return array<array|scalar|Constraint>
	 */
	protected function parseNodes(array $nodes)
	{
	}
}
/**
 * Loads validation metadata from a list of YAML files.
 *
 * @author Bulat Shakirzyanov <mallluhuct@gmail.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see FilesLoader
 */
class YamlFilesLoader extends \Symfony\Component\Validator\Mapping\Loader\FilesLoader
{
	/**
	 * {@inheritdoc}
	 */
	public function getFileLoaderInstance(string $file)
	{
	}
}

namespace Symfony\Component\Validator\Mapping;

/**
 * Stores all metadata needed for validating a class property.
 *
 * The value of the property is obtained by directly accessing the property.
 * The property will be accessed by reflection, so the access of private and
 * protected properties is supported.
 *
 * This class supports serialization and cloning.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see PropertyMetadataInterface
 */
class PropertyMetadata extends \Symfony\Component\Validator\Mapping\MemberMetadata
{
	/**
	 * @param string $class The class this property is defined on
	 * @param string $name  The name of this property
	 *
	 * @throws ValidatorException
	 */
	public function __construct(string $class, string $name)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getPropertyValue($object)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function newReflectionMember($objectOrClassName)
	{
	}
}
/**
 * Specifies whether and how a traversable object should be traversed.
 *
 * If the node traverser traverses a node whose value is an instance of
 * {@link \Traversable}, and if that node is either a class node or if
 * cascading is enabled, then the node's traversal strategy will be checked.
 * Depending on the requested traversal strategy, the node traverser will
 * iterate over the object and cascade each object or collection returned by
 * the iterator.
 *
 * The traversal strategy is ignored for arrays. Arrays are always iterated.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see CascadingStrategy
 */
class TraversalStrategy
{
	/**
	 * Specifies that a node's value should be iterated only if it is an
	 * instance of {@link \Traversable}.
	 */
	public const IMPLICIT = 1;
	/**
	 * Specifies that a node's value should never be iterated.
	 */
	public const NONE = 2;
	/**
	 * Specifies that a node's value should always be iterated. If the value is
	 * not an instance of {@link \Traversable}, an exception should be thrown.
	 */
	public const TRAVERSE = 4;
}

namespace Symfony\Component\Validator;

/**
 * Prepares an object for validation.
 *
 * Concrete implementations of this interface are used by {@link Validator\ContextualValidatorInterface}
 * to initialize objects just before validating them.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ObjectInitializerInterface
{
	public function initialize(object $object);
}

namespace Symfony\Component\Validator\Test;

/**
 * A test case to ease testing Constraint Validators.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
abstract class ConstraintValidatorTestCase extends \PHPUnit\Framework\TestCase
{
	/**
	 * @var ExecutionContextInterface
	 */
	protected $context;
	/**
	 * @var ConstraintValidatorInterface
	 */
	protected $validator;
	protected $group;
	protected $metadata;
	protected $object;
	protected $value;
	protected $root;
	protected $propertyPath;
	protected $constraint;
	protected $defaultTimezone;
	protected function setUp(): void
	{
	}
	protected function tearDown(): void
	{
	}
	protected function setDefaultTimezone(?string $defaultTimezone)
	{
	}
	protected function restoreDefaultTimezone()
	{
	}
	protected function createContext()
	{
	}
	protected function setGroup(?string $group)
	{
	}
	protected function setObject($object)
	{
	}
	protected function setProperty($object, $property)
	{
	}
	protected function setValue($value)
	{
	}
	protected function setRoot($root)
	{
	}
	protected function setPropertyPath(string $propertyPath)
	{
	}
	protected function expectNoValidate()
	{
	}
	protected function expectValidateAt(int $i, string $propertyPath, $value, $group)
	{
	}
	protected function expectValidateValue(int $i, $value, array $constraints = [], $group = null)
	{
	}
	protected function expectFailingValueValidation(int $i, $value, array $constraints, $group, \Symfony\Component\Validator\ConstraintViolationInterface $violation)
	{
	}
	protected function expectValidateValueAt(int $i, string $propertyPath, $value, $constraints, $group = null)
	{
	}
	protected function expectViolationsAt($i, $value, \Symfony\Component\Validator\Constraint $constraint)
	{
	}
	protected function assertNoViolation()
	{
	}
	/**
	 * @return ConstraintViolationAssertion
	 */
	protected function buildViolation($message)
	{
	}
	abstract protected function createValidator();
}
final class ConstraintViolationAssertion
{
	/**
	 * @internal
	 */
	public function __construct(\Symfony\Component\Validator\Context\ExecutionContextInterface $context, string $message, ?\Symfony\Component\Validator\Constraint $constraint = null, array $assertions = [])
	{
	}
	/**
	 * @return $this
	 */
	public function atPath(string $path)
	{
	}
	/**
	 * @return $this
	 */
	public function setParameter(string $key, string $value)
	{
	}
	/**
	 * @return $this
	 */
	public function setParameters(array $parameters)
	{
	}
	/**
	 * @return $this
	 */
	public function setTranslationDomain($translationDomain)
	{
	}
	/**
	 * @return $this
	 */
	public function setInvalidValue($invalidValue)
	{
	}
	/**
	 * @return $this
	 */
	public function setPlural(int $number)
	{
	}
	/**
	 * @return $this
	 */
	public function setCode(string $code)
	{
	}
	/**
	 * @return $this
	 */
	public function setCause($cause)
	{
	}
	public function buildNextViolation(string $message): self
	{
	}
	public function assertRaised()
	{
	}
}

namespace Symfony\Component\Validator\Validator;

/**
 * A validator in a specific execution context.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ContextualValidatorInterface
{
	/**
	 * Appends the given path to the property path of the context.
	 *
	 * If called multiple times, the path will always be reset to the context's
	 * original path with the given path appended to it.
	 *
	 * @return $this
	 */
	public function atPath(string $path);
	/**
	 * Validates a value against a constraint or a list of constraints.
	 *
	 * If no constraint is passed, the constraint
	 * {@link \Symfony\Component\Validator\Constraints\Valid} is assumed.
	 *
	 * @param mixed                                                 $value       The value to validate
	 * @param Constraint|Constraint[]|null                          $constraints The constraint(s) to validate against
	 * @param string|GroupSequence|array<string|GroupSequence>|null $groups      The validation groups to validate. If none is given, "Default" is assumed
	 *
	 * @return $this
	 */
	public function validate($value, $constraints = null, $groups = null);
	/**
	 * Validates a property of an object against the constraints specified
	 * for this property.
	 *
	 * @param string                                                $propertyName The name of the validated property
	 * @param string|GroupSequence|array<string|GroupSequence>|null $groups       The validation groups to validate. If none is given, "Default" is assumed
	 *
	 * @return $this
	 */
	public function validateProperty(object $object, string $propertyName, $groups = null);
	/**
	 * Validates a value against the constraints specified for an object's
	 * property.
	 *
	 * @param object|string                                         $objectOrClass The object or its class name
	 * @param string                                                $propertyName  The name of the property
	 * @param mixed                                                 $value         The value to validate against the property's constraints
	 * @param string|GroupSequence|array<string|GroupSequence>|null $groups        The validation groups to validate. If none is given, "Default" is assumed
	 *
	 * @return $this
	 */
	public function validatePropertyValue($objectOrClass, string $propertyName, $value, $groups = null);
	/**
	 * Returns the violations that have been generated so far in the context
	 * of the validator.
	 *
	 * @return ConstraintViolationListInterface
	 */
	public function getViolations();
}

namespace Symfony\Component\Validator\Test;

/**
 * @internal
 */
class AssertingContextualValidator implements \Symfony\Component\Validator\Validator\ContextualValidatorInterface
{
	public function __construct(\Symfony\Component\Validator\Context\ExecutionContextInterface $context)
	{
	}
	public function __destruct()
	{
	}
	public function atPath(string $path)
	{
	}
	/**
	 * @return $this
	 */
	public function doAtPath(string $path)
	{
	}
	public function validate($value, $constraints = null, $groups = null)
	{
	}
	/**
	 * @return $this
	 */
	public function doValidate($value, $constraints = null, $groups = null)
	{
	}
	public function validateProperty(object $object, string $propertyName, $groups = null)
	{
	}
	/**
	 * @return $this
	 */
	public function doValidateProperty(object $object, string $propertyName, $groups = null)
	{
	}
	public function validatePropertyValue($objectOrClass, string $propertyName, $value, $groups = null)
	{
	}
	/**
	 * @return $this
	 */
	public function doValidatePropertyValue($objectOrClass, string $propertyName, $value, $groups = null)
	{
	}
	public function getViolations(): \Symfony\Component\Validator\ConstraintViolationListInterface
	{
	}
	public function doGetViolations()
	{
	}
	public function expectNoValidate()
	{
	}
	public function expectValidation(string $call, ?string $propertyPath, $value, $group, callable $constraints, ?\Symfony\Component\Validator\ConstraintViolationInterface $violation = null)
	{
	}
}

namespace Symfony\Component\Validator\Util;

/**
 * Contains utility methods for dealing with property paths.
 *
 * For more extensive functionality, use Symfony's PropertyAccess component.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PropertyPath
{
	/**
	 * Appends a path to a given property path.
	 *
	 * If the base path is empty, the appended path will be returned unchanged.
	 * If the base path is not empty, and the appended path starts with a
	 * squared opening bracket ("["), the concatenation of the two paths is
	 * returned. Otherwise, the concatenation of the two paths is returned,
	 * separated by a dot (".").
	 *
	 * @return string
	 */
	public static function append(string $basePath, string $subPath)
	{
	}
}

namespace Symfony\Component\Validator;

/**
 * Entry point for the Validator component.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
final class Validation
{
	/**
	 * Creates a callable chain of constraints.
	 *
	 * @param Constraint|ValidatorInterface|null $constraintOrValidator
	 *
	 * @return callable($value)
	 */
	public static function createCallable($constraintOrValidator = null, \Symfony\Component\Validator\Constraint ...$constraints): callable
	{
	}
	/**
	 * Creates a callable that returns true/false instead of throwing validation exceptions.
	 *
	 * @param Constraint|ValidatorInterface|null $constraintOrValidator
	 *
	 * @return callable($value, &$violations = null): bool
	 */
	public static function createIsValidCallable($constraintOrValidator = null, \Symfony\Component\Validator\Constraint ...$constraints): callable
	{
	}
	/**
	 * Creates a new validator.
	 *
	 * If you want to configure the validator, use
	 * {@link createValidatorBuilder()} instead.
	 */
	public static function createValidator(): \Symfony\Component\Validator\Validator\ValidatorInterface
	{
	}
	/**
	 * Creates a configurable builder for validator objects.
	 */
	public static function createValidatorBuilder(): \Symfony\Component\Validator\ValidatorBuilder
	{
	}
}

namespace Symfony\Component\Validator\Validator;

/**
 * A wrapper for a callable initializing a property from a getter.
 *
 * @internal
 */
class LazyProperty
{
	public function __construct(\Closure $propertyValueCallback)
	{
	}
	public function getPropertyValue()
	{
	}
}
/**
 * Recursive implementation of {@link ContextualValidatorInterface}.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RecursiveContextualValidator implements \Symfony\Component\Validator\Validator\ContextualValidatorInterface
{
	/**
	 * Creates a validator for the given context.
	 *
	 * @param ObjectInitializerInterface[] $objectInitializers The object initializers
	 */
	public function __construct(\Symfony\Component\Validator\Context\ExecutionContextInterface $context, \Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface $metadataFactory, \Symfony\Component\Validator\ConstraintValidatorFactoryInterface $validatorFactory, array $objectInitializers = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function atPath(string $path)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, $constraints = null, $groups = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validateProperty(object $object, string $propertyName, $groups = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validatePropertyValue($objectOrClass, string $propertyName, $value, $groups = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getViolations()
	{
	}
	/**
	 * Normalizes the given group or list of groups to an array.
	 *
	 * @param string|GroupSequence|array<string|GroupSequence> $groups The groups to normalize
	 *
	 * @return array<string|GroupSequence>
	 */
	protected function normalizeGroups($groups)
	{
	}
}
/**
 * Validates PHP values against constraints.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ValidatorInterface extends \Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface
{
	/**
	 * Validates a value against a constraint or a list of constraints.
	 *
	 * If no constraint is passed, the constraint
	 * {@link \Symfony\Component\Validator\Constraints\Valid} is assumed.
	 *
	 * @param mixed                                                 $value       The value to validate
	 * @param Constraint|Constraint[]                               $constraints The constraint(s) to validate against
	 * @param string|GroupSequence|array<string|GroupSequence>|null $groups      The validation groups to validate. If none is given, "Default" is assumed
	 *
	 * @return ConstraintViolationListInterface A list of constraint violations
	 *                                          If the list is empty, validation
	 *                                          succeeded
	 */
	public function validate($value, $constraints = null, $groups = null);
	/**
	 * Validates a property of an object against the constraints specified
	 * for this property.
	 *
	 * @param string                                                $propertyName The name of the validated property
	 * @param string|GroupSequence|array<string|GroupSequence>|null $groups       The validation groups to validate. If none is given, "Default" is assumed
	 *
	 * @return ConstraintViolationListInterface A list of constraint violations
	 *                                          If the list is empty, validation
	 *                                          succeeded
	 */
	public function validateProperty(object $object, string $propertyName, $groups = null);
	/**
	 * Validates a value against the constraints specified for an object's
	 * property.
	 *
	 * @param object|string                                         $objectOrClass The object or its class name
	 * @param string                                                $propertyName  The name of the property
	 * @param mixed                                                 $value         The value to validate against the property's constraints
	 * @param string|GroupSequence|array<string|GroupSequence>|null $groups        The validation groups to validate. If none is given, "Default" is assumed
	 *
	 * @return ConstraintViolationListInterface A list of constraint violations
	 *                                          If the list is empty, validation
	 *                                          succeeded
	 */
	public function validatePropertyValue($objectOrClass, string $propertyName, $value, $groups = null);
	/**
	 * Starts a new validation context and returns a validator for that context.
	 *
	 * The returned validator collects all violations generated within its
	 * context. You can access these violations with the
	 * {@link ContextualValidatorInterface::getViolations()} method.
	 *
	 * @return ContextualValidatorInterface
	 */
	public function startContext();
	/**
	 * Returns a validator in the given execution context.
	 *
	 * The returned validator adds all generated violations to the given
	 * context.
	 *
	 * @return ContextualValidatorInterface
	 */
	public function inContext(\Symfony\Component\Validator\Context\ExecutionContextInterface $context);
}
/**
 * Recursive implementation of {@link ValidatorInterface}.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RecursiveValidator implements \Symfony\Component\Validator\Validator\ValidatorInterface
{
	protected $contextFactory;
	protected $metadataFactory;
	protected $validatorFactory;
	protected $objectInitializers;
	/**
	 * Creates a new validator.
	 *
	 * @param ObjectInitializerInterface[] $objectInitializers The object initializers
	 */
	public function __construct(\Symfony\Component\Validator\Context\ExecutionContextFactoryInterface $contextFactory, \Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface $metadataFactory, \Symfony\Component\Validator\ConstraintValidatorFactoryInterface $validatorFactory, array $objectInitializers = [])
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function startContext($root = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function inContext(\Symfony\Component\Validator\Context\ExecutionContextInterface $context)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getMetadataFor($object)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function hasMetadataFor($object)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, $constraints = null, $groups = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validateProperty(object $object, string $propertyName, $groups = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validatePropertyValue($objectOrClass, string $propertyName, $value, $groups = null)
	{
	}
}
/**
 * Collects some data about validator calls.
 *
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
class TraceableValidator implements \Symfony\Component\Validator\Validator\ValidatorInterface, \Symfony\Contracts\Service\ResetInterface
{
	public function __construct(\Symfony\Component\Validator\Validator\ValidatorInterface $validator)
	{
	}
	/**
	 * @return array
	 */
	public function getCollectedData()
	{
	}
	public function reset()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function getMetadataFor($value)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function hasMetadataFor($value)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validate($value, $constraints = null, $groups = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validateProperty(object $object, string $propertyName, $groups = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function validatePropertyValue($objectOrClass, string $propertyName, $value, $groups = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function startContext()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function inContext(\Symfony\Component\Validator\Context\ExecutionContextInterface $context)
	{
	}
}

namespace Symfony\Component\Validator;

/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ValidatorBuilder
{
	/**
	 * Adds an object initializer to the validator.
	 *
	 * @return $this
	 */
	public function addObjectInitializer(\Symfony\Component\Validator\ObjectInitializerInterface $initializer)
	{
	}
	/**
	 * Adds a list of object initializers to the validator.
	 *
	 * @param ObjectInitializerInterface[] $initializers
	 *
	 * @return $this
	 */
	public function addObjectInitializers(array $initializers)
	{
	}
	/**
	 * Adds an XML constraint mapping file to the validator.
	 *
	 * @return $this
	 */
	public function addXmlMapping(string $path)
	{
	}
	/**
	 * Adds a list of XML constraint mapping files to the validator.
	 *
	 * @param string[] $paths The paths to the mapping files
	 *
	 * @return $this
	 */
	public function addXmlMappings(array $paths)
	{
	}
	/**
	 * Adds a YAML constraint mapping file to the validator.
	 *
	 * @param string $path The path to the mapping file
	 *
	 * @return $this
	 */
	public function addYamlMapping(string $path)
	{
	}
	/**
	 * Adds a list of YAML constraint mappings file to the validator.
	 *
	 * @param string[] $paths The paths to the mapping files
	 *
	 * @return $this
	 */
	public function addYamlMappings(array $paths)
	{
	}
	/**
	 * Enables constraint mapping using the given static method.
	 *
	 * @return $this
	 */
	public function addMethodMapping(string $methodName)
	{
	}
	/**
	 * Enables constraint mapping using the given static methods.
	 *
	 * @param string[] $methodNames The names of the methods
	 *
	 * @return $this
	 */
	public function addMethodMappings(array $methodNames)
	{
	}
	/**
	 * Enables annotation based constraint mapping.
	 *
	 * @param bool $skipDoctrineAnnotations
	 *
	 * @return $this
	 */
	public function enableAnnotationMapping()
	{
	}
	/**
	 * Disables annotation based constraint mapping.
	 *
	 * @return $this
	 */
	public function disableAnnotationMapping()
	{
	}
	/**
	 * @return $this
	 */
	public function setDoctrineAnnotationReader(?\Doctrine\Common\Annotations\Reader $reader): self
	{
	}
	/**
	 * @return $this
	 */
	public function addDefaultDoctrineAnnotationReader(): self
	{
	}
	/**
	 * Sets the class metadata factory used by the validator.
	 *
	 * @return $this
	 */
	public function setMetadataFactory(\Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface $metadataFactory)
	{
	}
	/**
	 * Sets the cache for caching class metadata.
	 *
	 * @return $this
	 */
	public function setMappingCache(\Psr\Cache\CacheItemPoolInterface $cache)
	{
	}
	/**
	 * Sets the constraint validator factory used by the validator.
	 *
	 * @return $this
	 */
	public function setConstraintValidatorFactory(\Symfony\Component\Validator\ConstraintValidatorFactoryInterface $validatorFactory)
	{
	}
	/**
	 * Sets the translator used for translating violation messages.
	 *
	 * @return $this
	 */
	public function setTranslator(\Symfony\Contracts\Translation\TranslatorInterface $translator)
	{
	}
	/**
	 * Sets the default translation domain of violation messages.
	 *
	 * The same message can have different translations in different domains.
	 * Pass the domain that is used for violation messages by default to this
	 * method.
	 *
	 * @return $this
	 */
	public function setTranslationDomain(?string $translationDomain)
	{
	}
	/**
	 * @return $this
	 */
	public function addLoader(\Symfony\Component\Validator\Mapping\Loader\LoaderInterface $loader)
	{
	}
	/**
	 * @return LoaderInterface[]
	 */
	public function getLoaders()
	{
	}
	/**
	 * Builds and returns a new validator object.
	 *
	 * @return ValidatorInterface
	 */
	public function getValidator()
	{
	}
}

namespace Symfony\Component\Validator\Violation;

/**
 * Builds {@link \Symfony\Component\Validator\ConstraintViolationInterface}
 * objects.
 *
 * Use the various methods on this interface to configure the built violation.
 * Finally, call {@link addViolation()} to add the violation to the current
 * execution context.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ConstraintViolationBuilderInterface
{
	/**
	 * Stores the property path at which the violation should be generated.
	 *
	 * The passed path will be appended to the current property path of the
	 * execution context.
	 *
	 * @param string $path The property path
	 *
	 * @return $this
	 */
	public function atPath(string $path);
	/**
	 * Sets a parameter to be inserted into the violation message.
	 *
	 * @param string $key   The name of the parameter
	 * @param string $value The value to be inserted in the parameter's place
	 *
	 * @return $this
	 */
	public function setParameter(string $key, string $value);
	/**
	 * Sets all parameters to be inserted into the violation message.
	 *
	 * @param array $parameters An array with the parameter names as keys and
	 *                          the values to be inserted in their place as
	 *                          values
	 *
	 * @return $this
	 */
	public function setParameters(array $parameters);
	/**
	 * Sets the translation domain which should be used for translating the
	 * violation message.
	 *
	 * @param string $translationDomain The translation domain
	 *
	 * @return $this
	 *
	 * @see \Symfony\Contracts\Translation\TranslatorInterface
	 */
	public function setTranslationDomain(string $translationDomain);
	/**
	 * Sets the invalid value that caused this violation.
	 *
	 * @param mixed $invalidValue The invalid value
	 *
	 * @return $this
	 */
	public function setInvalidValue($invalidValue);
	/**
	 * Sets the number which determines how the plural form of the violation
	 * message is chosen when it is translated.
	 *
	 * @param int $number The number for determining the plural form
	 *
	 * @return $this
	 *
	 * @see \Symfony\Contracts\Translation\TranslatorInterface::trans()
	 */
	public function setPlural(int $number);
	/**
	 * Sets the violation code.
	 *
	 * @param string|null $code The violation code
	 *
	 * @return $this
	 */
	public function setCode(?string $code);
	/**
	 * Sets the cause of the violation.
	 *
	 * @param mixed $cause The cause of the violation
	 *
	 * @return $this
	 */
	public function setCause($cause);
	/**
	 * Adds the violation to the current execution context.
	 */
	public function addViolation();
}
/**
 * Default implementation of {@link ConstraintViolationBuilderInterface}.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @internal since version 2.5. Code against ConstraintViolationBuilderInterface instead.
 */
class ConstraintViolationBuilder implements \Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface
{
	/**
	 * @param string $message The error message as a string or a stringable object
	 */
	public function __construct(\Symfony\Component\Validator\ConstraintViolationList $violations, ?\Symfony\Component\Validator\Constraint $constraint, $message, array $parameters, $root, $propertyPath, $invalidValue, \Symfony\Contracts\Translation\TranslatorInterface $translator, $translationDomain = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function atPath(string $path)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setParameter(string $key, string $value)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setParameters(array $parameters)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setTranslationDomain(string $translationDomain)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setInvalidValue($invalidValue)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setPlural(int $number)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setCode(?string $code)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function setCause($cause)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function addViolation()
	{
	}
}

namespace Symfony\Component\Yaml\Command;

/**
 * Validates YAML files syntax and outputs encountered errors.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 * @author Robin Chalas <robin.chalas@gmail.com>
 */
class LintCommand extends \Symfony\Component\Console\Command\Command
{
	protected static $defaultName = 'lint:yaml';
	protected static $defaultDescription = 'Lint a YAML file and outputs encountered errors';
	public function __construct(?string $name = null, ?callable $directoryIteratorProvider = null, ?callable $isReadableProvider = null)
	{
	}
	/**
	 * {@inheritdoc}
	 */
	protected function configure()
	{
	}
	protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output)
	{
	}
	public function complete(\Symfony\Component\Console\Completion\CompletionInput $input, \Symfony\Component\Console\Completion\CompletionSuggestions $suggestions): void
	{
	}
}

namespace Symfony\Component\Yaml;

/**
 * Dumper dumps PHP variables to YAML strings.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @final
 */
class Dumper
{
	/**
	 * The amount of spaces to use for indentation of nested nodes.
	 *
	 * @var int
	 */
	protected $indentation;
	public function __construct(int $indentation = 4)
	{
	}
	/**
	 * Dumps a PHP value to YAML.
	 *
	 * @param mixed $input  The PHP value
	 * @param int   $inline The level where you switch to inline YAML
	 * @param int   $indent The level of indentation (used internally)
	 * @param int   $flags  A bit field of Yaml::DUMP_* constants to customize the dumped YAML string
	 */
	public function dump($input, int $inline = 0, int $indent = 0, int $flags = 0): string
	{
	}
}
/**
 * Escaper encapsulates escaping rules for single and double-quoted
 * YAML strings.
 *
 * @author Matthew Lewinski <matthew@lewinski.org>
 *
 * @internal
 */
class Escaper
{
	// Characters that would cause a dumped string to require double quoting.
	public const REGEX_CHARACTER_TO_ESCAPE = "[\\x00-\\x1f]||| | | ";
	/**
	 * Determines if a PHP value would require double quoting in YAML.
	 *
	 * @param string $value A PHP value
	 */
	public static function requiresDoubleQuoting(string $value): bool
	{
	}
	/**
	 * Escapes and surrounds a PHP value with double quotes.
	 *
	 * @param string $value A PHP value
	 */
	public static function escapeWithDoubleQuotes(string $value): string
	{
	}
	/**
	 * Determines if a PHP value would require single quoting in YAML.
	 *
	 * @param string $value A PHP value
	 */
	public static function requiresSingleQuoting(string $value): bool
	{
	}
	/**
	 * Escapes and surrounds a PHP value with single quotes.
	 *
	 * @param string $value A PHP value
	 */
	public static function escapeWithSingleQuotes(string $value): string
	{
	}
}

namespace Symfony\Component\Yaml\Exception;

/**
 * Exception interface for all exceptions thrown by the component.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
interface ExceptionInterface extends \Throwable
{
}
/**
 * Exception class thrown when an error occurs during parsing.
 *
 * @author Romain Neutron <imprec@gmail.com>
 */
class RuntimeException extends \RuntimeException implements \Symfony\Component\Yaml\Exception\ExceptionInterface
{
}
/**
 * Exception class thrown when an error occurs during dumping.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class DumpException extends \Symfony\Component\Yaml\Exception\RuntimeException
{
}
/**
 * Exception class thrown when an error occurs during parsing.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class ParseException extends \Symfony\Component\Yaml\Exception\RuntimeException
{
	/**
	 * @param string      $message    The error message
	 * @param int         $parsedLine The line where the error occurred
	 * @param string|null $snippet    The snippet of code near the problem
	 * @param string|null $parsedFile The file name where the error occurred
	 */
	public function __construct(string $message, int $parsedLine = -1, ?string $snippet = null, ?string $parsedFile = null, ?\Throwable $previous = null)
	{
	}
	/**
	 * Gets the snippet of code near the error.
	 *
	 * @return string
	 */
	public function getSnippet()
	{
	}
	/**
	 * Sets the snippet of code near the error.
	 */
	public function setSnippet(string $snippet)
	{
	}
	/**
	 * Gets the filename where the error occurred.
	 *
	 * This method returns null if a string is parsed.
	 *
	 * @return string
	 */
	public function getParsedFile()
	{
	}
	/**
	 * Sets the filename where the error occurred.
	 */
	public function setParsedFile(string $parsedFile)
	{
	}
	/**
	 * Gets the line where the error occurred.
	 *
	 * @return int
	 */
	public function getParsedLine()
	{
	}
	/**
	 * Sets the line where the error occurred.
	 */
	public function setParsedLine(int $parsedLine)
	{
	}
}

namespace Symfony\Component\Yaml;

/**
 * Inline implements a YAML parser/dumper for the YAML inline syntax.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @internal
 */
class Inline
{
	public const REGEX_QUOTED_STRING = '(?:"([^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+)"|\'([^\']*+(?:\'\'[^\']*+)*+)\')';
	public static $parsedLineNumber = -1;
	public static $parsedFilename;
	public static function initialize(int $flags, ?int $parsedLineNumber = null, ?string $parsedFilename = null)
	{
	}
	/**
	 * Converts a YAML string to a PHP value.
	 *
	 * @param string|null $value      A YAML string
	 * @param int         $flags      A bit field of Yaml::PARSE_* constants to customize the YAML parser behavior
	 * @param array       $references Mapping of variable names to values
	 *
	 * @return mixed
	 *
	 * @throws ParseException
	 */
	public static function parse(?string $value = null, int $flags = 0, array &$references = [], ?\Symfony\Component\Yaml\ParserState $state = null)
	{
	}
	/**
	 * Dumps a given PHP variable to a YAML string.
	 *
	 * @param mixed $value The PHP variable to convert
	 * @param int   $flags A bit field of Yaml::DUMP_* constants to customize the dumped YAML string
	 *
	 * @throws DumpException When trying to dump PHP resource
	 */
	public static function dump($value, int $flags = 0): string
	{
	}
	/**
	 * Check if given array is hash or just normal indexed array.
	 *
	 * @param array|\ArrayObject|\stdClass $value The PHP array or array-like object to check
	 */
	public static function isHash($value): bool
	{
	}
	/**
	 * Parses a YAML scalar.
	 *
	 * @return mixed
	 *
	 * @throws ParseException When malformed inline YAML string is parsed
	 */
	public static function parseScalar(string $scalar, int $flags = 0, ?array $delimiters = null, int &$i = 0, bool $evaluate = true, array &$references = [], ?bool &$isQuoted = null, ?\Symfony\Component\Yaml\ParserState $state = null)
	{
	}
	public static function evaluateBinaryScalar(string $scalar): string
	{
	}
}
/**
 * Parser parses YAML strings to convert them to PHP arrays.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @final
 */
class Parser
{
	public const TAG_PATTERN = '(?P<tag>![\w!.\/:-]+)';
	public const BLOCK_SCALAR_HEADER_PATTERN = '(?P<separator>\||>)(?P<modifiers>\+|\-|\d+|\+\d+|\-\d+|\d+\+|\d+\-)?(?P<comments> +#.*)?';
	public const REFERENCE_PATTERN = '#^&(?P<ref>[^ ]++) *+(?P<value>.*)#u';
	public const DEFAULT_MAX_NESTING_LEVEL = 128;
	public const DEFAULT_MAX_ALIASES_FOR_COLLECTIONS = 128;
	public function __construct(int $maxNestingLevel = self::DEFAULT_MAX_NESTING_LEVEL, int $maxAliasesForCollections = self::DEFAULT_MAX_ALIASES_FOR_COLLECTIONS)
	{
	}
	/**
	 * Parses a YAML file into a PHP value.
	 *
	 * @param string $filename The path to the YAML file to be parsed
	 * @param int    $flags    A bit field of Yaml::PARSE_* constants to customize the YAML parser behavior
	 *
	 * @return mixed
	 *
	 * @throws ParseException If the file could not be read or the YAML is not valid
	 */
	public function parseFile(string $filename, int $flags = 0)
	{
	}
	/**
	 * Parses a YAML string to a PHP value.
	 *
	 * @param string $value A YAML string
	 * @param int    $flags A bit field of Yaml::PARSE_* constants to customize the YAML parser behavior
	 *
	 * @return mixed
	 *
	 * @throws ParseException If the YAML is not valid
	 */
	public function parse(string $value, int $flags = 0)
	{
	}
	/**
	 * Returns the current line number (takes the offset into account).
	 *
	 * @internal
	 */
	public function getRealCurrentLineNb(): int
	{
	}
	/**
	 * A local wrapper for "preg_match" which will throw a ParseException if there
	 * is an internal error in the PCRE engine.
	 *
	 * This avoids us needing to check for "false" every time PCRE is used
	 * in the YAML engine
	 *
	 * @throws ParseException on a PCRE internal error
	 *
	 * @see preg_last_error()
	 *
	 * @internal
	 */
	public static function preg_match(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int
	{
	}
}
/**
 * @internal
 */
final class ParserState
{
	public $maxNestingLevel = \Symfony\Component\Yaml\Parser::DEFAULT_MAX_NESTING_LEVEL;
	public $currentNestingLevel = 0;
	public $maxAliasesForCollections = \Symfony\Component\Yaml\Parser::DEFAULT_MAX_ALIASES_FOR_COLLECTIONS;
	public $collectionAliasCount = 0;
	public $aliasesEnabled = true;
	public function reset(): void
	{
	}
	public function enterNestingLevel(int $line, ?string $snippet, ?string $filename): void
	{
	}
	public function leaveNestingLevel(): void
	{
	}
	/**
	 * @param mixed $refValue
	 */
	public function countAlias($refValue, int $line, ?string $snippet, ?string $filename): void
	{
	}
}

namespace Symfony\Component\Yaml\Tag;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 * @author Guilhem N. <egetick@gmail.com>
 */
final class TaggedValue
{
	public function __construct(string $tag, $value)
	{
	}
	public function getTag(): string
	{
	}
	public function getValue()
	{
	}
}

namespace Symfony\Component\Yaml;

/**
 * Unescaper encapsulates unescaping rules for single and double-quoted
 * YAML strings.
 *
 * @author Matthew Lewinski <matthew@lewinski.org>
 *
 * @internal
 */
class Unescaper
{
	/**
	 * Regex fragment that matches an escaped character in a double quoted string.
	 */
	public const REGEX_ESCAPED_CHARACTER = '\\\\(x[0-9a-fA-F]{2}|u[0-9a-fA-F]{4}|U[0-9a-fA-F]{8}|.)';
	/**
	 * Unescapes a single quoted string.
	 *
	 * @param string $value A single quoted string
	 */
	public function unescapeSingleQuotedString(string $value): string
	{
	}
	/**
	 * Unescapes a double quoted string.
	 *
	 * @param string $value A double quoted string
	 */
	public function unescapeDoubleQuotedString(string $value): string
	{
	}
}
/**
 * Yaml offers convenience methods to load and dump YAML.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @final
 */
class Yaml
{
	public const DUMP_OBJECT = 1;
	public const PARSE_EXCEPTION_ON_INVALID_TYPE = 2;
	public const PARSE_OBJECT = 4;
	public const PARSE_OBJECT_FOR_MAP = 8;
	public const DUMP_EXCEPTION_ON_INVALID_TYPE = 16;
	public const PARSE_DATETIME = 32;
	public const DUMP_OBJECT_AS_MAP = 64;
	public const DUMP_MULTI_LINE_LITERAL_BLOCK = 128;
	public const PARSE_CONSTANT = 256;
	public const PARSE_CUSTOM_TAGS = 512;
	public const DUMP_EMPTY_ARRAY_AS_SEQUENCE = 1024;
	public const DUMP_NULL_AS_TILDE = 2048;
	public const PARSE_EXCEPTION_ON_ALIAS = 8192;
	/**
	 * Parses a YAML file into a PHP value.
	 *
	 * Usage:
	 *
	 *     $array = Yaml::parseFile('config.yml');
	 *     print_r($array);
	 *
	 * @param string $filename                 The path to the YAML file to be parsed
	 * @param int    $flags                    A bit field of PARSE_* constants to customize the YAML parser behavior
	 * @param int    $maxNestingLevel          The maximum nesting depth for nested YAML blocks
	 * @param int    $maxAliasesForCollections The maximum number of collection aliases to resolve
	 *
	 * @return mixed
	 *
	 * @throws ParseException If the file could not be read or the YAML is not valid
	 */
	public static function parseFile(string $filename, int $flags = 0, int $maxNestingLevel = \Symfony\Component\Yaml\Parser::DEFAULT_MAX_NESTING_LEVEL, int $maxAliasesForCollections = \Symfony\Component\Yaml\Parser::DEFAULT_MAX_ALIASES_FOR_COLLECTIONS)
	{
	}
	/**
	 * Parses YAML into a PHP value.
	 *
	 *  Usage:
	 *  <code>
	 *   $array = Yaml::parse(file_get_contents('config.yml'));
	 *   print_r($array);
	 *  </code>
	 *
	 * @param string $input                    A string containing YAML
	 * @param int    $flags                    A bit field of PARSE_* constants to customize the YAML parser behavior
	 * @param int    $maxNestingLevel          The maximum nesting depth for nested YAML blocks
	 * @param int    $maxAliasesForCollections The maximum number of collection aliases to resolve
	 *
	 * @return mixed
	 *
	 * @throws ParseException If the YAML is not valid
	 */
	public static function parse(string $input, int $flags = 0, int $maxNestingLevel = \Symfony\Component\Yaml\Parser::DEFAULT_MAX_NESTING_LEVEL, int $maxAliasesForCollections = \Symfony\Component\Yaml\Parser::DEFAULT_MAX_ALIASES_FOR_COLLECTIONS)
	{
	}
	/**
	 * Dumps a PHP value to a YAML string.
	 *
	 * The dump method, when supplied with an array, will do its best
	 * to convert the array into friendly YAML.
	 *
	 * @param mixed $input  The PHP value
	 * @param int   $inline The level where you switch to inline YAML
	 * @param int   $indent The amount of spaces to use for indentation of nested nodes
	 * @param int   $flags  A bit field of DUMP_* constants to customize the dumped YAML string
	 */
	public static function dump($input, int $inline = 2, int $indent = 4, int $flags = 0): string
	{
	}
}

namespace Symfony\Contracts\Translation;

interface LocaleAwareInterface
{
	/**
	 * Sets the current locale.
	 *
	 * @param string $locale The locale
	 *
	 * @throws \InvalidArgumentException If the locale contains invalid characters
	 */
	public function setLocale(string $locale);
	/**
	 * Returns the current locale.
	 *
	 * @return string
	 */
	public function getLocale();
}

namespace Symfony\Contracts\Translation\Test;

/**
 * Test should cover all languages mentioned on http://translate.sourceforge.net/wiki/l10n/pluralforms
 * and Plural forms mentioned on http://www.gnu.org/software/gettext/manual/gettext.html#Plural-forms.
 *
 * See also https://developer.mozilla.org/en/Localization_and_Plurals which mentions 15 rules having a maximum of 6 forms.
 * The mozilla code is also interesting to check for.
 *
 * As mentioned by chx http://drupal.org/node/1273968 we can cover all by testing number from 0 to 199
 *
 * The goal to cover all languages is to far fetched so this test case is smaller.
 *
 * @author Clemens Tolboom clemens@build2be.nl
 */
class TranslatorTest extends \PHPUnit\Framework\TestCase
{
	protected function setUp(): void
	{
	}
	protected function tearDown(): void
	{
	}
	/**
	 * @return TranslatorInterface
	 */
	public function getTranslator()
	{
	}
	/**
	 * @dataProvider getTransTests
	 */
	public function testTrans($expected, $id, $parameters)
	{
	}
	/**
	 * @dataProvider getTransChoiceTests
	 */
	public function testTransChoiceWithExplicitLocale($expected, $id, $number)
	{
	}
	/**
	 * @requires extension intl
	 *
	 * @dataProvider getTransChoiceTests
	 */
	public function testTransChoiceWithDefaultLocale($expected, $id, $number)
	{
	}
	/**
	 * @dataProvider getTransChoiceTests
	 */
	public function testTransChoiceWithEnUsPosix($expected, $id, $number)
	{
	}
	public function testGetSetLocale()
	{
	}
	/**
	 * @requires extension intl
	 */
	public function testGetLocaleReturnsDefaultLocaleIfNotSet()
	{
	}
	public static function getTransTests()
	{
	}
	public static function getTransChoiceTests()
	{
	}
	/**
	 * @dataProvider getInterval
	 */
	public function testInterval($expected, $number, $interval)
	{
	}
	public static function getInterval()
	{
	}
	/**
	 * @dataProvider getChooseTests
	 */
	public function testChoose($expected, $id, $number, $locale = null)
	{
	}
	public function testReturnMessageIfExactlyOneStandardRuleIsGiven()
	{
	}
	/**
	 * @dataProvider getNonMatchingMessages
	 */
	public function testThrowExceptionIfMatchingMessageCannotBeFound($id, $number)
	{
	}
	public static function getNonMatchingMessages()
	{
	}
	public static function getChooseTests()
	{
	}
	/**
	 * @dataProvider failingLangcodes
	 */
	public function testFailedLangcodes($nplural, $langCodes)
	{
	}
	/**
	 * @dataProvider successLangcodes
	 */
	public function testLangcodes($nplural, $langCodes)
	{
	}
	/**
	 * This array should contain all currently known langcodes.
	 *
	 * As it is impossible to have this ever complete we should try as hard as possible to have it almost complete.
	 *
	 * @return array
	 */
	public static function successLangcodes()
	{
	}
	/**
	 * This array should be at least empty within the near future.
	 *
	 * This both depends on a complete list trying to add above as understanding
	 * the plural rules of the current failing languages.
	 *
	 * @return array with nplural together with langcodes
	 */
	public static function failingLangcodes()
	{
	}
	/**
	 * We validate only on the plural coverage. Thus the real rules is not tested.
	 *
	 * @param string $nplural       Plural expected
	 * @param array  $matrix        Containing langcodes and their plural index values
	 * @param bool   $expectSuccess
	 */
	protected function validateMatrix($nplural, $matrix, $expectSuccess = true)
	{
	}
	protected function generateTestData($langCodes)
	{
	}
}

namespace Symfony\Contracts\Translation;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
interface TranslatableInterface
{
	public function trans(\Symfony\Contracts\Translation\TranslatorInterface $translator, ?string $locale = null): string;
}
/**
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @method string getLocale() Returns the default locale
 */
interface TranslatorInterface
{
	/**
	 * Translates the given message.
	 *
	 * When a number is provided as a parameter named "%count%", the message is parsed for plural
	 * forms and a translation is chosen according to this number using the following rules:
	 *
	 * Given a message with different plural translations separated by a
	 * pipe (|), this method returns the correct portion of the message based
	 * on the given number, locale and the pluralization rules in the message
	 * itself.
	 *
	 * The message supports two different types of pluralization rules:
	 *
	 * interval: {0} There are no apples|{1} There is one apple|]1,Inf] There are %count% apples
	 * indexed:  There is one apple|There are %count% apples
	 *
	 * The indexed solution can also contain labels (e.g. one: There is one apple).
	 * This is purely for making the translations more clear - it does not
	 * affect the functionality.
	 *
	 * The two methods can also be mixed:
	 *     {0} There are no apples|one: There is one apple|more: There are %count% apples
	 *
	 * An interval can represent a finite set of numbers:
	 *  {1,2,3,4}
	 *
	 * An interval can represent numbers between two numbers:
	 *  [1, +Inf]
	 *  ]-1,2[
	 *
	 * The left delimiter can be [ (inclusive) or ] (exclusive).
	 * The right delimiter can be [ (exclusive) or ] (inclusive).
	 * Beside numbers, you can use -Inf and +Inf for the infinite.
	 *
	 * @see https://en.wikipedia.org/wiki/ISO_31-11
	 *
	 * @param string      $id         The message id (may also be an object that can be cast to string)
	 * @param array       $parameters An array of parameters for the message
	 * @param string|null $domain     The domain for the message or null to use the default
	 * @param string|null $locale     The locale or null to use the default
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException If the locale contains invalid characters
	 */
	public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null);
}
/**
 * A trait to help implement TranslatorInterface and LocaleAwareInterface.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
trait TranslatorTrait
{
	private $locale;
	/**
	 * {@inheritdoc}
	 */
	public function setLocale(string $locale)
	{
	}
	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function getLocale()
	{
	}
	/**
	 * {@inheritdoc}
	 */
	public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
	{
	}
	/**
	 * Returns the plural position to use for the given locale and number.
	 *
	 * The plural rules are derived from code of the Zend Framework (2010-09-25),
	 * which is subject to the new BSD license (http://framework.zend.com/license/new-bsd).
	 * Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
	 */
	private function getPluralizationRule(float $number, string $locale): int
	{
	}
}
