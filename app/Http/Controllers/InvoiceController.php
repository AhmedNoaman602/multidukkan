<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;

class InvoiceController extends Controller
{
    public function index()
    {
        $invoices = Invoice::latest()->get();
        return view('invoices.index', compact('invoices'));
    }
    public function show($id)
    {
        $invoice = Invoice::with('order.orderItems.product', 'order.customer')->findOrFail($id);
        $order = $invoice->order;
        return view('invoices.show', compact('invoice', 'order'));
    }
}
