<template>
    <div class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900">
        <!-- Navigation -->
        <nav class="flex justify-between items-center max-w-7xl mx-auto px-6 py-6">
            <h1 class="text-2xl font-bold text-white">Social Media Automation</h1>
            <div class="space-x-4">
                <Link href="/login" class="px-4 py-2 text-slate-300 hover:text-white transition-colors">
                    Sign In
                </Link>
                <Link href="/register"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                    Get Started
                </Link>
            </div>
        </nav>

        <!-- Hero -->
        <section class="max-w-4xl mx-auto px-6 py-24 text-center">
            <h2 class="text-5xl md:text-6xl font-bold text-white mb-6">
                Automate Your Social Media
            </h2>
            <p class="text-xl text-slate-300 mb-8">
                AI-powered social media management. Analyze comments, detect leads, and automate responses across all
                platforms.
            </p>
            <div class="space-x-4">
                <Link href="/register"
                    class="inline-block px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                    Start Free
                </Link>
                <Link href="/login"
                    class="inline-block px-8 py-3 border border-slate-600 hover:border-slate-500 text-slate-300 rounded-lg font-medium transition-colors">
                    Demo Login
                </Link>
            </div>
        </section>

        <!-- Features -->
        <section class="max-w-6xl mx-auto px-6 py-16">
            <h3 class="text-3xl font-bold text-white text-center mb-12">Features</h3>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <h4 class="text-xl font-bold text-white mb-3">🤖 AI Analysis</h4>
                    <p class="text-slate-400">
                        Automatically analyze sentiment, detect intent, and score leads using OpenAI
                    </p>
                </div>
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <h4 class="text-xl font-bold text-white mb-3">📊 Real-Time Analytics</h4>
                    <p class="text-slate-400">
                        Live dashboard with metrics updated in real-time via Pusher
                    </p>
                </div>
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <h4 class="text-xl font-bold text-white mb-3">🔗 Multi-Platform</h4>
                    <p class="text-slate-400">
                        Connect Facebook, Instagram, YouTube, Twitter, and LinkedIn
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>

<script setup>
import Link from '@/Components/Link.vue'
</script>