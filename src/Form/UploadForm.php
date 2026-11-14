<?php
declare(strict_types=1);

namespace EducationalCurriculum\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\InputFilter\InputFilterProviderInterface;

/**
 * The three files of a published release. Omeka adds the CSRF field to forms it builds.
 */
class UploadForm extends Form implements InputFilterProviderInterface
{
    const FILES = ['curriculo_json', 'curriculo_sha256', 'cur_ttl'];

    public function init(): void
    {
        $this->setAttribute('enctype', 'multipart/form-data');
        $this->add([
            'name' => 'curriculo_json',
            'type' => Element\File::class,
            'options' => [
                'label' => 'curriculo.json', // @translate
                'info' => 'The curriculum of the release.', // @translate
            ],
            'attributes' => ['id' => 'curriculo-json', 'required' => true, 'accept' => '.json,application/json'],
        ]);
        $this->add([
            'name' => 'curriculo_sha256',
            'type' => Element\File::class,
            'options' => [
                'label' => 'curriculo.json.sha256', // @translate
                'info' => 'Its checksum, in the format of sha256sum.', // @translate
            ],
            'attributes' => ['id' => 'curriculo-sha256', 'required' => true, 'accept' => '.sha256'],
        ]);
        $this->add([
            'name' => 'cur_ttl',
            'type' => Element\File::class,
            'options' => [
                'label' => 'cur.ttl', // @translate
                'info' => 'The cur vocabulary of the same release.', // @translate
            ],
            'attributes' => ['id' => 'cur-ttl', 'required' => true, 'accept' => '.ttl,text/turtle'],
        ]);
        $this->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => ['value' => 'Upload and analyze'], // @translate
        ]);
    }

    /**
     * The three files are required on the server too, not only by the browser.
     */
    public function getInputFilterSpecification()
    {
        $specification = [];
        foreach (self::FILES as $name) {
            $specification[$name] = ['required' => true];
        }
        return $specification;
    }
}
