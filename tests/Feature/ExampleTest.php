<?php

it('reindirizza la home su Nova', function () {
    $response = $this->get('/');

    $response->assertRedirect('/nova');
});
