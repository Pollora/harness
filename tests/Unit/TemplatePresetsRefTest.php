<?php

declare(strict_types=1);

it('reads the presets of a template from the commit the workflow was called at', function (): void {
    $text = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/template.yml');
    preg_match_all('/repository: Pollora\/harness\n          ref: (.+)\n/', $text, $refs);

    // github.job_workflow_sha is not a property of the github context: it was empty, and checkout took the default branch
    expect($refs[1])->toBe(array_fill(0, 4, '${{ job.workflow_sha }}'));
});
