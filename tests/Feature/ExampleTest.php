<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path redirects guests toward the dashboard (then login).
     */
    public function test_the_root_path_redirects(): void
    {
        $response = $this->get('/');

        $response->assertRedirect();
    }
}
