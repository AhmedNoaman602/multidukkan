@extends('layout.app')

@section('title', 'Customers')
@section('page-title', 'Customers')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Customers</h2>
        <p class="page-header-subtitle">Manage your customer database</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
            </svg>
            Add Customer
        </button>
    </div>
</div>

<x-card :padding="false">
    <x-data-table :headers="['Customer', 'Email', 'Phone', 'Total Orders', 'Total Spent', 'Status', 'Actions']">
        <tr>
            <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-info) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; color: white;">
                        AM
                    </div>
                    <div>
                        <div style="font-weight: 600;">Ahmed Mohamed</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Customer since Jan 2026</div>
                    </div>
                </div>
            </td>
            <td style="color: var(--text-secondary);">ahmed@example.com</td>
            <td style="color: var(--text-secondary);">+20 123 456 7890</td>
            <td style="font-weight: 600;">12</td>
            <td style="font-weight: 600; color: var(--accent-success);">$1,245.00</td>
            <td><span class="badge success">Active</span></td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-icon btn-secondary btn-sm" title="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                    <button class="btn btn-icon btn-secondary btn-sm" title="Edit">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--accent-success) 0%, #059669 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; color: white;">
                        SA
                    </div>
                    <div>
                        <div style="font-weight: 600;">Sara Ali</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Customer since Dec 2025</div>
                    </div>
                </div>
            </td>
            <td style="color: var(--text-secondary);">sara@example.com</td>
            <td style="color: var(--text-secondary);">+20 987 654 3210</td>
            <td style="font-weight: 600;">8</td>
            <td style="font-weight: 600; color: var(--accent-success);">$890.00</td>
            <td><span class="badge success">Active</span></td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-icon btn-secondary btn-sm" title="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                    <button class="btn btn-icon btn-secondary btn-sm" title="Edit">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr>
    </x-data-table>
</x-card>
@endsection
