<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Form;

use EducationalCurriculum\Form\UploadForm;
use Laminas\Form\Element\File;
use Laminas\Form\Element\Submit;
use PHPUnit\Framework\TestCase;

class UploadFormTest extends TestCase
{
    public function testAsksForTheThreeFilesOfARelease(): void
    {
        $form = new UploadForm();
        $form->init();

        $this->assertSame('multipart/form-data', $form->getAttribute('enctype'));
        foreach (['curriculo_json', 'curriculo_sha256', 'cur_ttl'] as $name) {
            $this->assertInstanceOf(File::class, $form->get($name));
            $this->assertTrue($form->getInputFilter()->get($name)->isRequired(), "$name is required");
        }
        $this->assertSame('.json,application/json', $form->get('curriculo_json')->getAttribute('accept'));
        $this->assertInstanceOf(Submit::class, $form->get('submit'));
    }
}
