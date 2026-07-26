<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = ActivityLog::query()
            ->with('user:id,name')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('model'), fn ($q) => $q->where('auditable_type', 'App\\Models\\'.$request->string('model')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest()
            ->paginate(30)
            ->withQueryString()
            ->through(fn ($log) => [
                'id' => $log->id,
                'at' => $log->created_at?->format('Y-m-d H:i'),
                'user' => $log->user?->name ?? 'System',
                'action' => $log->action,
                'model' => $log->model_name,
                'label' => $log->label,
                'changes' => $log->changes,
            ]);

        return Inertia::render('Activity/Index', [
            'logs' => $logs,
            'filters' => $request->only(['action', 'model', 'user_id', 'from', 'to']),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'models' => ['Transaction', 'Customer', 'Reference', 'Vehicle', 'PaymentMethod', 'ExpenseCategory', 'Bank', 'User'],
            'actions' => ['created', 'updated', 'deleted', 'restored', 'force_deleted'],
        ]);
    }
}
