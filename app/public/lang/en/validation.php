<?php

// Field names in validation messages are the labels people see on the form. Laravel merges this file over its own
// texts, so only the names live here.
//   attributes       field name => label, for every form
//   form_attributes  request path pattern => labels that differ on that form (AppServiceProvider applies them)
return [
    'attributes' => [
        // Sign-in, account, confirmation
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'password_confirmation' => 'Repeat new password',
        'current_password' => 'Current password',
        'confirm_password' => 'Current password',
        'bootstrap_code' => 'Activation code',
        'code' => '6-digit code',
        // Entries
        'environment_id' => 'Project / environment',
        'alias' => 'Agent alias',
        'type' => 'Type',
        'tags' => 'Tags',
        'rotate_at' => 'Rotation reminder',
        'secret' => 'Secret values',
        'secret_fields' => 'Secret values',
        'clear_fields' => 'Clear the current value',
        'version' => 'Version',
        'confirmation' => 'Type DELETE',
        'import' => 'Entries (JSON)',
        // Agents, grants, members
        'days' => 'Valid for (days)',
        'machine_id' => 'Agent',
        'entry_id' => 'Entry',
        'action' => 'Action',
        'approval' => 'Ask me before every run',
        'decision' => 'Decision',
        'role' => 'Role',
        // Setup
        'project_id' => 'Project',
        'env_name' => 'Environment',
        'env_production' => 'Production',
        'template' => 'Environment template',
        'field_label' => 'Field label',
        'field_kind' => 'Kind',
        'field_required' => 'Required',
        'field_remove' => 'Remove',
        'fields' => 'Fields',
        // Filters
        'q' => 'Search',
        'project' => 'Project',
        'environment' => 'Environment',
        'state' => 'State',
        'agent' => 'Agent',
        'entry' => 'Entry',
        'group' => 'Event group',
        'actor' => 'Actor',
    ],
    'form_attributes' => [
        'password' => ['password' => 'New password'],
        'workspaces/*/members' => ['password' => 'First password'],
        'workspaces/*/operations/*/mfa' => ['code' => 'Site MFA code (6–8 digits)'],
        'workspaces/*/types*' => ['name' => 'Type name'],
    ],
];
