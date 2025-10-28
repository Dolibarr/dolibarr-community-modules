<?php
namespace GoetasWebservices\Xsd\XsdToPhpRuntime\Tests\Jms\Handler;

use Doctrine\Common\Annotations\AnnotationReader;
use GoetasWebservices\Xsd\XsdToPhpRuntime\Jms\Handler\XmlSchemaDateHandler;
use JMS\Serializer\Accessor\DefaultAccessorStrategy;
use JMS\Serializer\Construction\UnserializeObjectConstructor;
use JMS\Serializer\DeserializationContext;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\GraphNavigator;
use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\Metadata\Driver\AnnotationDriver;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\XmlDeserializationVisitor;
use JMS\Serializer\XmlSerializationVisitor;
use Metadata\MetadataFactory;
use PHPUnit\Framework\TestCase;

class XmlSchemaDateHandlerDeserializationTest extends TestCase
{
    /**
     * @var XmlSchemaDateHandler
     */
    protected $handler;
    /**
     * @var DeserializationContext
     */
    protected $context;

    /**
     * @var XmlDeserializationVisitor
     */
    protected $visitor;

    public function setUp(): void
    {
        $this->handler = new XmlSchemaDateHandler();
        $this->context = DeserializationContext::create();
        $naming = new IdenticalPropertyNamingStrategy();



        $dispatcher = new EventDispatcher();
        $handlerRegistry= new HandlerRegistry();
        $cons = new UnserializeObjectConstructor();

        $navigator = class_exists('JMS\Serializer\GraphNavigator\DeserializationGraphNavigator')
            ? $this->initJmsv2($naming, $handlerRegistry, $cons, $dispatcher)
            : $this->initJmsv1($naming, $handlerRegistry, $cons, $dispatcher)
        ;
        $this->visitor->setNavigator($navigator);
    }

    private function initJmsv2($naming, $handlerRegistry, $cons, $dispatcher)
    {
        $accessor = new DefaultAccessorStrategy();
        $this->visitor = new XmlDeserializationVisitor();
        $metadataFactory = new MetadataFactory(new AnnotationDriver(new AnnotationReader(), $naming));
        return new GraphNavigator\DeserializationGraphNavigator($metadataFactory, $handlerRegistry, $cons, $accessor, $dispatcher);
    }

    private function initJmsv1($naming, $handlerRegistry, $cons, $dispatcher)
    {
        $this->visitor = new XmlDeserializationVisitor($naming);
        $metadataFactory = new MetadataFactory(new AnnotationDriver(new AnnotationReader()));
        return new GraphNavigator($metadataFactory, $handlerRegistry, $cons, $dispatcher);
    }

    /**
     * @dataProvider getDeserializeDate
     * @param string    $date
     * @param \DateTime $expected
     */
    public function testDeserializeDate($date, \DateTime $expected)
    {
        $element = new \SimpleXMLElement("<Date>$date</Date>");
        $deserialized = $this->handler->deserializeDate($this->visitor, $element, [], $this->context);
        $this->assertEquals($expected, $deserialized);
    }

    /**
     * @dataProvider getDeserializeDateInterval
     * @param string    $dateInterval
     * @param \DateTime $expected
     */
    public function testDeserializeDateInterval($dateInterval, $expected)
    {
        $element = new \SimpleXMLElement("<DateInterval>$dateInterval</DateInterval>");
        $deserialized = $this->handler->deserializeDateIntervalXml($this->visitor, $element, [], $this->context);
        if (isset($deserialized->f)) {
            $this->assertEquals($expected['f'], $deserialized->f);
        }
        $this->assertEquals($expected['s'], $deserialized->s);
    }

    public function getDeserializeDateInterval()
    {
        return [
            ['P0Y0M0DT3H5M7.520S', ['s' => 7, 'f' => 0.52]],
            ['P0Y0M0DT3H5M7S', ['s' => 7, 'f' => 0]]
        ];
    }

    public function getDeserializeDate()
    {
        return [
            ['2015-01-01', new \DateTime('2015-01-01')],
            ['2015-01-01Z', new \DateTime('2015-01-01', new \DateTimeZone("UTC"))],
            ['2015-01-01+06:00', new \DateTime('2015-01-01', new \DateTimeZone("+06:00"))],
            ['2015-01-01-20:00', new \DateTime('2015-01-01', new \DateTimeZone("-20:00"))],
        ];
    }

    public function testDeserializeInvalidDate()
    {
        $this->expectException(\RuntimeException::class);
        $element = new \SimpleXMLElement("<Date>2015-01-01T</Date>");
        $this->handler->deserializeDate($this->visitor, $element, [], $this->context);
    }
}
