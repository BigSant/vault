<?php

// Validation texts. Laravel merges this file over its own, so it holds only what differs.
//   rule messages    one sentence per rule the app uses, starting with the field's visible label:
//                    "Name is required.", "Valid for (days) must be 30 or less."
//   attributes       field name => the label people see on the form
//   form_attributes  request path pattern => labels that differ on that form (AppServiceProvider applies them)
// Messages name the field and the rule, never the value that was sent.
return [
    'required' => ':attribute is required.',
    'string' => ':attribute must be text.',
    'integer' => ':attribute must be a whole number.',
    'numeric' => ':attribute must be a number.',
    'array' => ':attribute must be a list.',
    'boolean' => ':attribute must be yes or no.',
    'email' => ':attribute must be a valid email address.',
    'uuid' => ':attribute is not a valid choice.',
    'in' => ':attribute is not one of the allowed choices.',
    'exists' => ':attribute is not a valid choice.',
    'regex' => ':attribute has characters or a format that is not allowed.',
    'confirmed' => ':attribute and its repeat do not match.',
    'date' => ':attribute must be a date.',
    'date_format' => ':attribute must be a date like 2026-12-31.',
    'max' => [
        'numeric' => ':attribute must be :max or less.',
        'string' => ':attribute must be :max characters or fewer.',
        'array' => ':attribute must have :max items or fewer.',
        'file' => ':attribute must be :max kilobytes or less.',
    ],
    'min' => [
        'numeric' => ':attribute must be :min or more.',
        'string' => ':attribute must be at least :min characters.',
        'array' => ':attribute must have at least :min items.',
        'file' => ':attribute must be at least :min kilobytes.',
    ],
    'size' => [
        'numeric' => ':attribute must be :size.',
        'string' => ':attribute must be exactly :size characters.',
        'array' => ':attribute must have exactly :size items.',
        'file' => ':attribute must be :size kilobytes.',
    ],
    'custom' => [
        'code' => ['regex' => ':attribute must be 6 to 8 digits.', 'size' => ':attribute must be exactly 6 digits.'],
        'alias' => ['regex' => ':attribute may use letters, digits, spaces, dots, dashes and underscores only.'],
        'confirmation' => ['in' => 'Type DELETE in capital letters to destroy the entry.'],
    ],
    'attributes' => [
        // Sign-in, account, confirmation
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'password_confirmation' => 'Repeat new password',
        'current_password' => 'Current password',
        'confirm_password' => 'Current password',
        'bootstrap_code' => 'Activation code',
        'code' => 'Authenticator code',
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
        // The label's hint "(6–8 digits)" is left out: the message says it.
        'workspaces/*/operations/*/mfa' => ['code' => 'Site MFA code'],
        'workspaces/*/types*' => ['name' => 'Type name'],
    ],
];
