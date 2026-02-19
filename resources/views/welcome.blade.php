<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page Not Found | Organization</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .animate-float {
            animation: float 6s ease-in-out infinite;
        }

        .animate-pulse-slow {
            animation: pulse 4s ease-in-out infinite;
        }

        .animate-shimmer {
            animation: shimmer 3s infinite linear;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            background-size: 1000px 100%;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-15px);
            }
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 0.3;
            }

            50% {
                opacity: 0.1;
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }

            100% {
                background-position: 1000px 0;
            }
        }
    </style>
</head>

<body class="min-h-screen flex flex-col">
    <!-- Top Navigation Bar (Similar to dashboard) -->
    <div class="sticky top-0 z-10 bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo and Brand -->
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <img src="remove.png" alt="ogo" class="w-8 h-8" />
                        <span class="text-lg font-bold text-gray-900">Organization</span>
                    </div>
                </div>

                <!-- Login Link -->
                <div>
                    <a href="" class="text-blue-600 hover:text-blue-800 text-sm font-medium transition-colors">
                        Portal Login →
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content - Flat Design -->
    <main class="flex-1">
        <!-- Background Elements -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <!-- Floating elements -->
            <div
                class="absolute top-1/4 left-10 w-72 h-72 bg-blue-100 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-float">
            </div>
            <div class="absolute top-1/3 right-10 w-96 h-96 bg-indigo-100 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-float"
                style="animation-delay: 2s;"></div>
            <div class="absolute bottom-1/4 left-1/3 w-64 h-64 bg-purple-100 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-float"
                style="animation-delay: 4s;"></div>

            <!-- Grid pattern -->
            <div class="absolute inset-0"
                style="background-image: linear-gradient(to right, rgba(59, 130, 246, 0.05) 1px, transparent 1px), linear-gradient(to bottom, rgba(59, 130, 246, 0.05) 1px, transparent 1px); background-size: 40px 40px;">
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-24">
            <div class="flex flex-col lg:flex-row items-center gap-12 lg:gap-20">
                <!-- Left Column - Illustration -->
                <div class="lg:w-1/2">
                    <div class="relative">
                        <!-- 404 Number -->
                        <div class="text-center mb-8">
                            <div class="relative inline-block">
                                <span
                                    class="text-9xl font-black bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 bg-clip-text text-transparent">
                                    404
                                </span>
                                <div
                                    class="absolute -inset-4 bg-gradient-to-r from-blue-100 via-indigo-100 to-purple-100 rounded-full blur-3xl opacity-30 animate-pulse-slow">
                                </div>
                            </div>

                            <p class="text-2xl font-semibold text-gray-700 mt-4">
                                Page Not Found
                            </p>
                        </div>

                        <!-- Abstract Illustration -->
                        <div class="relative mt-12">
                            <!-- Main circle -->
                            <div class="relative w-64 h-64 mx-auto">
                                <!-- Outer ring -->
                                <div
                                    class="absolute inset-0 bg-gradient-to-r from-blue-100 to-indigo-100 rounded-full animate-float">
                                </div>

                                <!-- Middle ring -->
                                <div class="absolute inset-8 bg-gradient-to-r from-white to-blue-50 rounded-full shadow-lg animate-float"
                                    style="animation-delay: 1s;"></div>

                                <!-- Inner circle -->
                                <div
                                    class="absolute inset-16 bg-gradient-to-br from-blue-50 to-white rounded-full flex items-center justify-center shadow-inner">
                                    <div
                                        class="w-16 h-16 bg-gradient-to-r from-blue-400 to-indigo-400 rounded-full animate-pulse-slow">
                                    </div>
                                </div>

                                <!-- Floating dots -->
                                <div class="absolute -top-4 left-1/2 -translate-x-1/2 w-8 h-8 bg-white rounded-full shadow-lg border border-blue-100 animate-float"
                                    style="animation-delay: 0.5s;"></div>
                                <div class="absolute -bottom-4 left-1/2 -translate-x-1/2 w-6 h-6 bg-blue-200 rounded-full shadow-lg animate-float"
                                    style="animation-delay: 1.5s;"></div>
                                <div class="absolute top-1/2 -left-4 -translate-y-1/2 w-4 h-4 bg-indigo-300 rounded-full shadow-lg animate-float"
                                    style="animation-delay: 2.5s;"></div>
                                <div class="absolute top-1/2 -right-4 -translate-y-1/2 w-4 h-4 bg-purple-300 rounded-full shadow-lg animate-float"
                                    style="animation-delay: 3.5s;"></div>
                            </div>

                            <!-- Connecting lines -->
                            <div
                                class="absolute top-1/2 left-0 w-full h-0.5 bg-gradient-to-r from-transparent via-blue-200 to-transparent">
                            </div>
                            <div
                                class="absolute top-0 left-1/2 h-full w-0.5 bg-gradient-to-b from-transparent via-blue-200 to-transparent">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Content -->
                <div class="lg:w-1/2">
                    <div class="space-y-8">
                        <!-- Welcome Message -->
                        <div>
                            <h1 class="text-4xl md:text-5xl font-bold text-gray-900 leading-tight">
                                Lost in the
                                <span
                                    class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">
                                    Digital Space
                                </span>
                            </h1>
                            <div class="w-24 h-1 bg-gradient-to-r from-blue-500 to-indigo-500 rounded-full mt-4"></div>
                        </div>

                        <!-- Description -->
                        <div class="space-y-6">
                            <p class="text-lg text-gray-600 leading-relaxed">
                                The page you're trying to reach doesn't exist or may have been moved.
                                This could be due to an outdated link or a mistyped URL.
                            </p>

                            <!-- Information Card -->
                            <div
                                class="bg-gradient-to-r from-blue-50/50 to-indigo-50/50 backdrop-blur-sm border border-blue-100 rounded-xl p-6">
                                <div class="flex items-start gap-4">
                                    <div class="flex-shrink-0">
                                        <div
                                            class="w-12 h-12 bg-gradient-to-br from-blue-100 to-white rounded-lg flex items-center justify-center shadow-sm">
                                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900 mb-2">What happened?</h3>
                                        <ul class="space-y-2 text-gray-600">
                                            <li class="flex items-center gap-2">
                                                <div class="w-1.5 h-1.5 bg-blue-400 rounded-full"></div>
                                                <span>The URL might be incorrect</span>
                                            </li>
                                            <li class="flex items-center gap-2">
                                                <div class="w-1.5 h-1.5 bg-blue-400 rounded-full"></div>
                                                <span>The page may have been relocated</span>
                                            </li>
                                            <li class="flex items-center gap-2">
                                                <div class="w-1.5 h-1.5 bg-blue-400 rounded-full"></div>
                                                <span>You might not have access to this resource</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="space-y-4">
                            <h3 class="font-semibold text-gray-900">Quick Navigation</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <a href="https://portal.organizationstaff.org/login"
                                    class="group bg-white border border-gray-200 rounded-xl p-4 hover:border-blue-300 hover:shadow-md transition-all duration-300">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-10 h-10 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                                            </svg>
                                        </div>
                                        <div>
                                            <p
                                                class="font-medium text-gray-900 group-hover:text-blue-600 transition-colors">
                                                Portal Login</p>
                                            <p class="text-xs text-gray-500">Access the Portal</p>
                                        </div>
                                    </div>
                                </a>

                                <a href="https://organizationstaff.org"
                                    class="group bg-white border border-gray-200 rounded-xl p-4 hover:border-indigo-300 hover:shadow-md transition-all duration-300">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-10 h-10 bg-gradient-to-br from-indigo-50 to-indigo-100 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                            </svg>
                                        </div>
                                        <div>
                                            <p
                                                class="font-medium text-gray-900 group-hover:text-indigo-600 transition-colors">
                                                Main Website</p>
                                            <p class="text-xs text-gray-500">Visit Homepage</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>

                        <!-- Technical Info -->
                        <div class="pt-6 border-t border-gray-100">
                            <div class="flex items-center justify-between text-sm text-gray-500">
                                <div class="flex items-center gap-4">
                                    <span>Error Code: <span class="font-semibold text-gray-700">404</span></span>
                                    <span>•</span>
                                    <span>Time: <span class="font-semibold text-gray-700"
                                            id="currentTime"></span></span>
                                </div>
                                <span>Request ID: <span
                                        class="font-mono text-gray-700">-<?php echo substr(md5(uniqid()), 0, 8); ?></span></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="border-t border-gray-200 bg-white/80 backdrop-blur-sm">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <img src="remove.png" alt="ogo" class="w-5 h-5 opacity-70" />
                    <span class="text-sm text-gray-600">Organization</span>
                </div>
                <div class="text-xs text-gray-500">
                    <span id="currentDate"></span> •
                    <span id="currentTime2"></span> •
                    v1.0
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Update time and date
        function updateDateTime() {
            const now = new Date();

            // Format time (HH:MM AM/PM)
            const timeOptions = { hour: '2-digit', minute: '2-digit', hour12: true };
            const timeString = now.toLocaleTimeString('en-US', timeOptions);

            // Format date (Month Day, Year)
            const dateOptions = { year: 'numeric', month: 'long', day: 'numeric' };
            const dateString = now.toLocaleDateString('en-US', dateOptions);

            document.getElementById('currentTime').textContent = timeString;
            document.getElementById('currentTime2').textContent = timeString;
            document.getElementById('currentDate').textContent = dateString;
        }

        // Update immediately and every minute
        updateDateTime();
        setInterval(updateDateTime, 60000);

        // Smooth hover effects
        document.querySelectorAll('a').forEach(link => {
            link.addEventListener('mouseenter', function () {
                this.style.transform = 'translateY(-2px)';
            });

            link.addEventListener('mouseleave', function () {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>

</html>