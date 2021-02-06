import Vue from "vue";
import VueRouter from "vue-router";
import Console from "../views/Console";

Vue.use(VueRouter);

export default new VueRouter({
    mode: "history",
    routes: [
        { path: "/console", component: Console}
    ]
});