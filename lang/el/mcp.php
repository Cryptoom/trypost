<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Συνδέστε βοηθούς AI για να δημιουργούν και να διαχειρίζονται αναρτήσεις σε αυτό το workspace.',
    'step_add' => 'Επικολλήστε το όνομα, το URL ή το config παρακάτω στην εφαρμογή σας. Η σύνδεση ανοίγει στο πρόγραμμα περιήγησης την πρώτη φορά.',
    'name_label' => 'Όνομα',
    'url_label' => 'URL διακομιστή',
    'config_label' => 'Config',
    'connected_title' => 'Συνδεδεμένες εφαρμογές',
    'connected_description' => 'Βοηθοί που έχουν ήδη συνδεθεί. Η αποσύνδεση κόβει την πρόσβαση αμέσως.',
    'connected_empty' => 'Τίποτα συνδεδεμένο ακόμα. Χρησιμοποιήστε Claude, ChatGPT ή άλλο client παραπάνω.',
    'disconnect' => 'Αποσύνδεση',
    'disconnect_title' => 'Αποσύνδεση εφαρμογής',
    'disconnect_confirm' => 'Αυτό αποσυνδέει την εφαρμογή από το TryPost. Θα χρειαστεί να συνδεθεί ξανά πριν χρησιμοποιήσει το MCP.',
    'disconnected' => 'Η εφαρμογή αποσυνδέθηκε.',
    'last_used' => 'Τελευταία χρήση',
    'never' => 'Ποτέ',
    'documentation_title' => 'Τεκμηρίωση',
    'documentation_description' => 'Οδηγοί ανά client, διαθέσιμα tools και αντιμετώπιση προβλημάτων.',
    'view_docs' => 'Δείτε την τεκμηρίωση',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Άλλες εφαρμογές',
    'other_clients_description' => 'Cursor, VS Code, Claude Code και ό,τι άλλο μιλάει MCP.',

    'clients' => [
        'cursor' => 'Προσθέστε το TryPost ως απομακρυσμένο MCP server στο Cursor.',
        'vscode' => 'Προσθέστε το TryPost μέσω του εγκαταστάτη MCP του VS Code.',
        'claude_code' => 'Προσθέστε τον server με μία εντολή CLI.',
        'other' => 'Λειτουργεί με κάθε client που διαβάζει config mcpServers.',
        'other_name' => 'Άλλα',
    ],
];
