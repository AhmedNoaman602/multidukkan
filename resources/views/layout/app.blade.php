<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MultiDukkan - Multi-tenant Store Management Dashboard">
    <title>@yield('title', 'Dashboard') - MultiDukkan</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    
    @stack('styles')
    
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Overlay for Mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
        
        <!-- Sidebar -->
        @include('components.sidebar')
        
        <!-- Main Wrapper -->
        <div class="main-wrapper">
            <!-- Header -->
            @include('components.header')
            
            <!-- Main Content -->
            <main class="main-content">
                <!-- @if (session('success'))
                    <div style="background-color: rgba(16, 185, 129, 0.15); border: 1px solid var(--accent-success); border-radius: var(--radius-md); padding: 12px 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                        <svg style="width: 20px; height: 20px; color: var(--accent-success);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <span style="color: var(--text-primary); font-size: 14px;">{{ session('success') }}</span>
                    </div>
                @endif -->
                
                @yield('content')
            </main>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }
    </script>
    
    @stack('scripts')
</body>
</html>