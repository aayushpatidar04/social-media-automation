<template>
    <AuthLayout title="Create Account">
        <form @submit.prevent="submit" class="space-y-6">
            <!-- Name -->
            <div>
                <label for="name" class="block text-sm font-medium text-slate-300 mb-2">
                    Full Name
                </label>
                <input id="name" v-model="form.name" type="text" required
                    class="w-full px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="John Doe" />
                <p v-if="form.errors.name" class="text-red-400 text-sm mt-1">
                    {{ form.errors.name }}
                </p>
            </div>

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
                <p class="text-slate-400 text-xs mt-1">
                    Must be at least 8 characters long
                </p>
                <p v-if="form.errors.password" class="text-red-400 text-sm mt-1">
                    {{ form.errors.password }}
                </p>
            </div>

            <!-- Confirm Password -->
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-300 mb-2">
                    Confirm Password
                </label>
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" required
                    class="w-full px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="••••••••" />
                <p v-if="form.errors.password_confirmation" class="text-red-400 text-sm mt-1">
                    {{ form.errors.password_confirmation }}
                </p>
            </div>

            <!-- Agreement -->
            <div class="flex items-start">
                <input id="agree" v-model="form.agree" type="checkbox" required
                    class="w-4 h-4 text-blue-500 bg-slate-800 border-slate-600 rounded focus:ring-blue-500 mt-1" />
                <label for="agree" class="ml-2 text-sm text-slate-300">
                    I agree to the
                    <a href="/terms" class="text-blue-400 hover:text-blue-300">Terms of Service</a>
                    and
                    <a href="/privacy" class="text-blue-400 hover:text-blue-300">Privacy Policy</a>
                </label>
            </div>

            <!-- Submit Button -->
            <button type="submit" :disabled="form.processing"
                class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-600 text-white font-medium rounded-lg transition-colors">
                <span v-if="!form.processing">Create Account</span>
                <span v-else>Creating Account...</span>
            </button>

            <!-- Link to Login -->
            <div class="text-center text-sm text-slate-300">
                Already have an account?
                <Link href="/login" class="text-blue-400 hover:text-blue-300 ml-1">
                    Sign in
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import Link from '@/Components/Link.vue'

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    agree: false,
})

const submit = () => {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>