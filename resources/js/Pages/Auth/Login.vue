<template>
    <AuthLayout title="Sign In">
        <form @submit.prevent="submit" class="space-y-6">
            <!-- Email -->
            <div>
                <label for="email" class="block text-sm font-medium text-slate-300 mb-2">
                    Email Address
                </label>
                <input id="email" v-model="form.email" type="email" required
                    class="w-full px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="you@example.com" />
                <p v-if="form.errors.email" class="text-red-400 text-sm mt-1">
                    {{ form.errors.email }}
                </p>
            </div>

            <!-- Password -->
            <div>
                <label for="password" class="block text-sm font-medium text-slate-300 mb-2">
                    Password
                </label>
                <input id="password" v-model="form.password" type="password" required
                    class="w-full px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="••••••••" />
                <p v-if="form.errors.password" class="text-red-400 text-sm mt-1">
                    {{ form.errors.password }}
                </p>
            </div>

            <!-- Remember Me -->
            <div class="flex items-center">
                <input id="remember" v-model="form.remember" type="checkbox"
                    class="w-4 h-4 text-blue-500 bg-slate-800 border-slate-600 rounded focus:ring-blue-500" />
                <label for="remember" class="ml-2 text-sm text-slate-300">
                    Remember me
                </label>
            </div>

            <!-- Error Message -->
            <div v-if="form.errors.login" class="p-4 bg-red-900/30 border border-red-700 rounded-lg text-red-200">
                {{ form.errors.login }}
            </div>

            <!-- Submit Button -->
            <button type="submit" :disabled="form.processing"
                class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-600 text-white font-medium rounded-lg transition-colors">
                <span v-if="!form.processing">Sign In</span>
                <span v-else>Signing In...</span>
            </button>

            <!-- Links -->
            <div class="flex items-center justify-between text-sm">
                <Link href="/forgot-password" class="text-blue-400 hover:text-blue-300">
                    Forgot password?
                </Link>
                <div>
                    Don't have an account?
                    <Link href="/register" class="text-blue-400 hover:text-blue-300 ml-1">
                        Sign up
                    </Link>
                </div>
            </div>
        </form>

        <!-- Demo Credentials -->
        <div class="mt-8 pt-8 border-t border-slate-700">
            <p class="text-sm text-slate-400 mb-4">💡 Demo Credentials:</p>
            <div class="space-y-2 text-sm text-slate-400">
                <div class="p-2 bg-slate-800 rounded text-slate-300">
                    <span class="font-mono text-blue-400">admin@example.com</span> / <span
                        class="font-mono">password</span>
                </div>
            </div>
        </div>
    </AuthLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import Link from '@/Components/Link.vue'

const form = useForm({
    email: 'admin@example.com',
    password: 'password',
    remember: false,
})

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>